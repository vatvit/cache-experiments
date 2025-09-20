# Cache Operation Flow - Stale-While-Revalidate Strategy

## Overview

The cache implements a sophisticated 5-tier resolution strategy that balances performance, consistency, and cache stampede prevention. This document details the complete flow with timing diagrams and decision points.

## 5-Tier Cache Resolution Strategy

```
Cache.get(key) Entry Point
          │
          ▼
┌─────────────────────────┐
│  1. Try Fresh Hit       │ ◄─── Fast Path (Hot Cache)
│  ┌─────────────────────┐│
│  │ Check: item.isHit() ││
│  │ AND within soft TTL ││
│  └─────────────────────┘│
└─────────┬───────────────┘
          │ MISS/STALE
          ▼
┌─────────────────────────┐
│  2. Leader Acquire Lock │ ◄─── Single-Flight Pattern
│  ┌─────────────────────┐│
│  │ won = item.lock()   ││
│  └─────────────────────┘│
└─────┬───────────────────┘
      │ LOCK SUCCESS      │ LOCK FAILED
      ▼                   ▼
┌─────────────────┐   ┌─────────────────────────┐
│  Leader Path    │   │  3. Follower Serve Stale│ ◄─── Stale-While-Revalidate
│                 │   │  ┌─────────────────────┐│
│ Load + Save     │   │  │ Use Invalidation    ││
│ Return Fresh    │   │  │ Mode: OLD          ││
│                 │   │  └─────────────────────┘│
└─────────────────┘   └─────┬───────────────────┘
                            │ NO STALE DATA
                            ▼
                      ┌─────────────────────────┐
                      │  4. Follower Wait Fresh │ ◄─── Cooperative Waiting
                      │  ┌─────────────────────┐│
                      │  │ Use Invalidation    ││
                      │  │ Mode: SLEEP         ││
                      │  │ (150ms × 6 = 900ms) ││
                      │  └─────────────────────┘│
                      └─────┬───────────────────┘
                            │ STILL NO DATA
                            ▼
                      ┌─────────────────────────┐
                      │  5. Fail-Open Compute   │ ◄─── Last Resort
                      │  ┌─────────────────────┐│
                      │  │ Load without cache  ││
                      │  │ OR return miss      ││
                      │  └─────────────────────┘│
                      └─────────────────────────┘
```

## Detailed Flow Sequence

### Tier 1: Fresh Hit (Fast Path)

```
Client Request
      │
      ▼
┌─────────────────────────────────────────┐
│ item = pool.getItem(key.toString())     │
│ item.setInvalidationMethod(PRECOMPUTE)  │
│ value = item.get()                      │
└─────────────────────────────────────────┘
      │
      ▼
┌─────────────────────────────────────────┐
│ Check Conditions:                       │
│ ✓ item.isHit() == true                 │
│ ✓ now < softExpiresAt                  │
└─────────────────────────────────────────┘
      │ SUCCESS
      ▼
┌─────────────────────────────────────────┐
│ metrics.inc('cache_hit', 'fresh')       │
│ return ValueResult.hit(value, ...)      │
└─────────────────────────────────────────┘
```

**Timing Characteristics:**
- **Latency**: ~1-5ms (Redis GET operation)
- **TTL Window**: `createdAt` → `softExpiresAt` → `hardExpiresAt`
- **Soft Window**: `hardExpiresAt - precomputeSec`

### Tier 2: Leader Path (Lock Winner)

```
Lock Acquisition Attempt
      │
      ▼
┌─────────────────────────────────────────┐
│ won = item.lock()                       │
│ if (won) {                             │
│   try {                                │
│     // Critical Section                │
│   } finally {                          │
│     unset(item); // Auto-unlock       │
│   }                                    │
│ }                                      │
└─────────────────────────────────────────┘
      │ LOCK SUCCESS
      ▼
┌─────────────────────────────────────────┐
│ Leader Computation:                     │
│                                         │
│ 1. loaded = loader.resolve(key)         │
│ 2. save(key, loaded)                    │
│ 3. metrics.inc('cache_fill')            │
│ 4. return ValueResult.hit(loaded, ...)  │
└─────────────────────────────────────────┘
```

**Timing Characteristics:**
- **Lock Duration**: Depends on `loader.resolve()` execution time
- **Cache Write**: Includes TTL jitter application
- **Blocking**: All other requests wait or follow fallback paths

### Tier 3: Follower Serve Stale

```
Lock Failed (Another Leader Active)
      │
      ▼
┌─────────────────────────────────────────┐
│ item = pool.getItem(key.toString())     │
│ item.setInvalidationMethod(OLD)         │
│ stale = item.get()                      │
└─────────────────────────────────────────┘
      │
      ▼
┌─────────────────────────────────────────┐
│ if (stale !== null) {                   │
│   metrics.inc('cache_hit', 'stale')     │
│   return ValueResult.stale(stale, ...)  │
│ }                                       │
└─────────────────────────────────────────┘
```

**Key Features:**
- **Invalidation Mode OLD**: Returns previous value even if locked
- **Non-blocking**: Immediate response with stale data
- **Graceful Degradation**: Serves last known good value

### Tier 4: Follower Wait Fresh

```
No Stale Data Available
      │
      ▼
┌─────────────────────────────────────────┐
│ item = pool.getItem(key.toString())     │
│ item.setInvalidationMethod(             │
│   SLEEP, 150ms, 6 retries              │
│ )                                       │
│ waited = item.get()                     │
└─────────────────────────────────────────┘
      │
      ▼
┌─────────────────────────────────────────┐
│ Retry Logic:                            │
│ ┌─ Attempt 1: Sleep 150ms ─┐           │
│ ├─ Attempt 2: Sleep 150ms ─┤           │
│ ├─ Attempt 3: Sleep 150ms ─┤           │
│ ├─ Attempt 4: Sleep 150ms ─┤           │
│ ├─ Attempt 5: Sleep 150ms ─┤           │
│ └─ Attempt 6: Sleep 150ms ─┘           │
│ Total Max Wait: ~900ms                  │
└─────────────────────────────────────────┘
      │ SUCCESS
      ▼
┌─────────────────────────────────────────┐
│ if (item.isHit()) {                     │
│   metrics.inc('cache_hit',              │
│     'fresh_after_sleep')                │
│   return ValueResult.hit(waited, ...)   │
│ }                                       │
└─────────────────────────────────────────┘
```

**Timing Characteristics:**
- **Max Wait Time**: 900ms (6 × 150ms)
- **Progressive Backoff**: Fixed 150ms intervals
- **Success Condition**: Leader completes and saves fresh data

### Tier 5: Fail-Open Compute

```
All Previous Tiers Failed
      │
      ▼
┌─────────────────────────────────────────┐
│ Check Fail-Open Policy:                 │
│                                         │
│ if (failOpen ?? true) {                 │
│   // Compute without caching           │
│   fallback = loader.resolve(key)        │
│   metrics.inc('cache_miss',             │
│     'precompute_race')                  │
│   return ValueResult.hit(fallback, ...) │
│ } else {                                │
│   // Fail closed                       │
│   metrics.inc('cache_miss',             │
│     'precompute_race_fail_closed')      │
│   return ValueResult.miss()             │
│ }                                       │
└─────────────────────────────────────────┘
```

**Policy Options:**
- **Fail-Open**: Compute fresh data without caching (default)
- **Fail-Closed**: Return cache miss, application handles fallback

## Cache Stampede Prevention

### Problem Scenario
```
Without Protection:
    Request 1 ──┐
    Request 2 ──┼─→ Cache Miss ─→ All compute simultaneously
    Request 3 ──┘                 ↓
                                Database/API Overload
```

### Solution Implementation
```
With Leader/Follower:
    Request 1 ──→ Leader (Lock) ─→ Compute ─→ Save ─→ Fresh Result
    Request 2 ──→ Follower ─────→ Stale ──────────→ Stale Result
    Request 3 ──→ Follower ─────→ Wait ───────────→ Fresh Result
```

### Lock Coordination Flow
```
Timeline: 0ms ────────── 500ms ────────── 1000ms ───────→

Request A: ├─ Lock ─├─ Compute ─├─ Save ─├ Release
           │        │           │        │
Request B: ├─ Try ──┤ (Failed)  │        │
           │        └─ Stale ───┤        │
           │                    │        │
Request C: ├─ Try ──┤ (Failed)  │        │
           │        └─ Wait ────┼────────├─ Fresh
```

## Performance Characteristics

### Latency Distribution
- **Fresh Hit**: 1-5ms (Redis GET)
- **Leader Path**: 50-500ms (compute time dependent)
- **Stale Serve**: 1-5ms (Redis GET with OLD mode)
- **Follower Wait**: 150-900ms (progressive sleep)
- **Fail-Open**: 50-500ms (compute time dependent)

### Throughput Impact
- **Cache Hit Ratio**: 95%+ typical with proper TTL tuning
- **Stampede Prevention**: 99%+ reduction in duplicate computations
- **Stale Tolerance**: Graceful degradation during high load

## Monitoring and Metrics

### Key Metrics
```php
// Hit types
'cache_hit' => ['state' => 'fresh']
'cache_hit' => ['state' => 'stale']
'cache_hit' => ['state' => 'fresh_after_sleep']

// Misses
'cache_miss' => ['cause' => 'precompute_race']
'cache_miss' => ['cause' => 'precompute_race_fail_closed']

// Operations
'cache_fill'           // Leader computations
'cache_put'            // Explicit puts
'cache_invalidate'     // Exact invalidations
'cache_invalidate_hierarchical' // Prefix invalidations
```

### Alerting Thresholds
- **Stale Hit Rate > 10%**: Indicates frequent cache expirations
- **Fail-Open Rate > 1%**: Suggests load or timeout issues
- **Average Wait Time > 200ms**: Potential lock contention