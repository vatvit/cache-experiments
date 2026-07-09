# Cache Library — Consumer Requirements

Requirements for a TS/Node port of this cache library, written from the perspective of a real
consuming application (a game backend: Node/Express + PostgreSQL + Redis, with a synchronous
physics/AI planner and an async job/worker system). Captures what that app would need in order to
actually adopt the library. Priorities: **MUST** = adoption blocker, **NICE** = valuable, **NON** =
explicitly out of scope so the lib isn't over-built.

## Context: two cache shapes the lib must serve

Most requirements below fall out of supporting **both** shapes with one library.

- **Shape A — durable, offline-computed artifact.** Expensive derived data (seconds–minutes to
  compute) that must survive restarts and be shared across instances. Backend = **PostgreSQL**
  (canonical, queryable). Computed by **background jobs**, never on the read thread. Consumed by a
  **synchronous** hot path (a planner that cannot `await` per key). Serving stale is acceptable
  *because a downstream verifier re-checks the result* (a stale guide can only slow things down,
  never produce a wrong answer).
- **Shape B — hot read-path cache.** Request-synchronous reads (HTTP/WS) where a miss/expiry would
  stall a user. Backend = **Redis**. Classic **stale-while-revalidate** is the win.

Guiding principle observed in the host app: **Redis is ephemeral (TTL) and never the source of
truth; canonical results live in PostgreSQL.** The lib must not assume Redis is canonical.

## MUST have

1. **Pluggable `Store` backend.** At least a **Redis** adapter and a **PostgreSQL** adapter behind
   one interface (`get / set / delete / mget / lock`). Canonical results must be able to live in
   Postgres.

2. **Explicit read state, not just a value.** Return
   `{ value, state: FRESH | STALE | MISS, computedAt, softExpiresAt, hardExpiresAt }`. The consumer
   must be able to *act on* staleness (one consumer serves a stale value + revalidates; another may
   choose to block). (This is the PoC's `CacheReadState` — keep it.)

3. **Version-gated correctness, independent of TTL and invalidation.** A read must **never** return
   a value whose embedded version ≠ current, *even if the purge/invalidation job never runs*. The
   key embeds a version and the read filters on it. This makes "the invalidation trigger was lost"
   a non-event: TTL/purge are **housekeeping (reclaim space), not correctness**. A lost cache entry
   is also safe — it becomes a miss → recompute.

4. **Structured, versioned, content-addressed keys.** Beyond `domain/facet/id/version/locale`, the
   key identity must be able to fold in:
   - a **composite code version** (e.g. `ALGO_VERSION * 1000 + PHYSICS_VERSION`),
   - a **content hash** of the canonical inputs (so an edit to the source auto-outdates the row
     without a version bump), and
   - arbitrary **variant tags** (e.g. an ability/profile signature — the same computation yields
     different results per variant).
   A version bump or content change must be a **structural miss** (different key), never a stale hit.

5. **Pluggable single-flight / refresh strategy — delegable to an external job queue.** The host app
   already has cross-instance single-flight + async refresh via its job system (atomic dedup claim →
   worker → Postgres). The lib must let the app **inject how to refresh** so it can *enqueue a job*
   instead of running its own Redis lock — otherwise it duplicates existing machinery. Provide a
   built-in Redis-lock strategy **and** a `refresh: (key) => Promise<void>` injection point.

6. **Batch prime → synchronous read (for synchronous consumers).** The hot path is **synchronous**
   and cannot `await` per key. Requirement: an async **`prime(keys[])`** that loads a batch into an
   in-memory snapshot, then a **sync `getPrimed(key)`** for the hot path. Without this, Shape A
   cannot consume the cache at all.

7. **Soft TTL + hard TTL + stale-while-revalidate.** `softExpiresAt = hardExpiresAt −
   precomputeWindow`. Within soft → FRESH; between soft and hard → STALE + trigger refresh (via the
   strategy in #5); past hard or version-miss → MISS. This is the self-healing answer to silent
   staleness (e.g. a forgotten version bump heals within the soft window).

8. **Negative caching with its own (shorter) TTL.** The app caches "no result / not-found /
   not-reachable" outcomes with a **short** negative TTL, distinct from a normal miss. The loader
   must be able to return a negative result cached under its own TTL.

9. **Custom per-cache serialization.** Values include non-JSON-native types (`Set`, `Map`,
   structured records). Require injectable `serialize / deserialize` per cache (default JSON).

10. **Cross-instance safety.** Multiple API + worker instances run concurrently. Single-flight and
    invalidation must coordinate **across processes** (Redis lock or job dedup), never rely on
    in-process state.

## NICE to have

11. **Jitter on write TTL** for stampede spreading (already in the PoC).
12. **Batch `mget` / batch invalidate.**
13. **Invalidation: exact + prefix/hierarchical.** Prefix helps Shape B (Redis read caches); Shape A
    invalidates structurally by version, so prefix is optional / backend-specific.
14. **Observability hooks** — injectable metrics/event sink (counters for
    `hit{fresh|stale}`, `miss`, `fill`, `refresh_enqueued`, `single_flight_coalesced`). PSR-14-style
    event dispatch is fine; a simple sink interface is enough.
15. **Fail-open vs fail-closed policy** per cache. Note: the synchronous **follower sleep-wait
    ladder** is *not* needed by these consumers (Shape A computes-or-gives-up; Shape B serves stale)
    — keep it optional.

## Non-functional

- **TypeScript, generic over value type** (`Cache<T>`); strict types on the read-state result.
- **No heavy deps.** Don't hard-bind a Redis client or a Postgres driver — accept an injected client
  / query function so the host app supplies its own.
- **Unit-testable with injected deps.** The orchestration (soft/hard TTL, single-flight, refresh,
  negative caching) must be testable with **no real Redis/Postgres** (inject fakes). The host app
  unit-tests store/scheduler logic exactly this way.

## Explicit NON-requirements (don't over-build)

- No synchronous follower **sleep-wait retry ladder** (consumers compute-or-give-up, or serve stale).
- No hierarchical prefix invalidation for the durable/precompute caches (they invalidate by version).
- **Do not** treat Redis as the canonical store — PostgreSQL must be a first-class backend.
