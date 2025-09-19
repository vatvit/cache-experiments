<?php

namespace Cache;

use Cache\Interface\CacheInterface;
use Cache\Interface\PsrPoolAccessInterface;
use Cache\Interface\KeyInterface;
use Cache\Interface\LoaderInterface;
use Cache\Interface\JitterInterface;
use Cache\Interface\KeyPrefixInterface;
use Cache\Interface\MetricsInterface;
use Cache\Interface\ValueResultInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Stash\Interfaces\ItemInterface;
use Stash\Interfaces\PoolInterface as StashPoolInterface;
use Stash\Invalidation;

class Cache implements CacheInterface, PsrPoolAccessInterface
{
    public function __construct(
        private StashPoolInterface            $pool,
        private LoaderInterface               $loader,
        private int                           $hardTtlSec,
        private int                           $precomputeSec,         // seconds BEFORE hard TTL to precompute (soft window)
        private JitterInterface               $jitter,
        private EventDispatcherInterface|null $eventDispatcher = null,
        private MetricsInterface|null         $metrics = null,
    )
    {
        if ($hardTtlSec < 1) throw new \InvalidArgumentException('hardTtlSec must be >= 1');
        if ($precomputeSec < 0 || $precomputeSec > $hardTtlSec) {
            throw new \InvalidArgumentException('precomputeSec must be in [0, hardTtlSec]');
        }
    }

    public function get(KeyInterface $key): ValueResultInterface
    {
        $item = $this->pool->getItem($key->toString());

        // 1) fast path: fresh hit
        if ($result = $this->tryFreshHit($item)) {
            return $result;
        }

        // 2) single-flight: become the leader and recompute
        $won = $item->lock();
        if ($won) {
            try {
                return $this->leaderComputeAndSave($key);
            } finally {
                // lock is released when $item is out of scope (Stash frees it with Item lifecycle)
                unset($item);
            }
        }

        // 3) follower path: serve stale
        if ($result = $this->tryFollowerServeStale($key)) {
            return $result;
        }

        // 4) follower path: wait for leader to finish
        if ($result = $this->tryFollowerWaitFresh($key)) {
            return $result;
        }

        // 5) last resort: fail-open compute or miss
        return $this->failOpenOrMiss($key);
    }

    /** Extract createdAt and soft boundary from Stash item. */
    private function timestampsFromItem(ItemInterface $item): array
    {
        $createdAt = $item->getCreation() ? $item->getCreation()->getTimestamp() : time();
        $expiresAt = $item->getExpiration() ? $item->getExpiration()->getTimestamp() : ($createdAt + $this->hardTtlSec);
        $softAt = $expiresAt - $this->precomputeSec;
        if ($softAt < $createdAt) {
            $softAt = $createdAt;
        }
        return [$createdAt, $softAt];
    }

    /** Try to return a fresh hit if available. Returns null on miss. */
    private function tryFreshHit(ItemInterface $item): ?ValueResultInterface
    {
        $item->setInvalidationMethod(Invalidation::PRECOMPUTE, $this->precomputeSec);
        $value = $item->get();
        if ($item->isHit()) {
            [$createdAt, $softAt] = $this->timestampsFromItem($item);
            $this->metrics?->inc('cache_hit', ['state' => 'fresh']);
            return ValueResult::hit($value, $createdAt, $softAt);
        }
        return null;
    }

    /** Leader path: compute and save, returning a fresh hit result. */
    private function leaderComputeAndSave(KeyInterface $key): ValueResultInterface
    {
        $loaded = $this->loader->resolve($key);
        $this->save($key, $loaded); // sets hard TTL (with jitter) and stores value
        $this->metrics?->inc('cache_fill');

        // recompute times deterministically after save
        $now = time();
        $hard = $now + $this->hardTtlSec;
        $soft = $hard - $this->precomputeSec;
        if ($soft < $now) {
            $soft = $now;
        }

        return ValueResult::hit($loaded, $now, $soft);
    }

    /** Follower: try to serve stale value while leader holds the lock. */
    private function tryFollowerServeStale(KeyInterface $key): ?ValueResultInterface
    {
        $item = $this->pool->getItem($key->toString());
        $item->setInvalidationMethod(Invalidation::OLD); // serve previous value if locked by another process
        $stale = $item->get();
        if ($stale !== null) {
            [$createdAt, $softAt] = $this->timestampsFromItem($item);
            $this->metrics?->inc('cache_hit', ['state' => 'stale']);
            return ValueResult::stale($stale, $createdAt, $softAt);
        }
        return null;
    }

    /** Follower: short wait for leader to finish, then try to return fresh. */
    private function tryFollowerWaitFresh(KeyInterface $key): ?ValueResultInterface
    {
        $item = $this->pool->getItem($key->toString());
        $item->setInvalidationMethod(Invalidation::SLEEP, 150, 6); // 6x150ms = ~900ms max wait
        $waited = $item->get();
        if ($item->isHit()) {
            [$createdAt, $softAt] = $this->timestampsFromItem($item);
            $this->metrics?->inc('cache_hit', ['state' => 'fresh_after_sleep']);
            return ValueResult::hit($waited, $createdAt, $softAt);
        }
        return null;
    }

    /** Last resort: compute fail-open (do not save) or return miss if fail-closed. */
    private function failOpenOrMiss(KeyInterface $key): ValueResultInterface
    {
        if ($this->failOpen ?? true) {
            $fallback = $this->loader->resolve($key);
            $now = time();
            $hard = $now + $this->hardTtlSec;
            $soft = max($now, $hard - $this->precomputeSec);
            $this->metrics?->inc('cache_miss', ['cause' => 'precompute_race']);
            return ValueResult::hit($fallback, $now, $soft); // computed value, not yet cached
        }

        $this->metrics?->inc('cache_miss', ['cause' => 'precompute_race_fail_closed']);
        return ValueResult::miss();
    }

    public function getMany(iterable $keys): \SplObjectStorage
    {
        // naive: iterate; real impl may group and use resolveMany()
        $map = new \SplObjectStorage();
        foreach ($keys as $k) {
            $map[$k] = $this->get($k);
        }
        return $map;
    }

    public function put(KeyInterface $key, mixed $value): void
    {
        $this->save($key, $value);
        $this->metrics?->inc('cache_put');
    }

    private function save(KeyInterface $key, mixed $value): void
    {
        $item = $this->pool->getItem($key->toString());
        // Stash TTL is hard TTL; add jitter if configured
        $hardTtl = $this->jitter?->apply($this->hardTtlSec, $key) ?? $this->hardTtlSec;

        // PSR-6: store raw value; Stash keeps creation/expiration internally
        $item->set($value);
        $item->expiresAfter($hardTtl);
        $this->pool->save($item);
    }

    public function invalidate(KeyPrefixInterface|KeyInterface|array $selectors, SyncMode $mode = SyncMode::ASYNC): void
    {
        foreach ((array)$selectors as $selector) {
            if ($mode === SyncMode::ASYNC) {
                $this->eventDispatcher->dispatch(new AsyncEvent($selector, false));
                return;
            }

            $this->pool->getDriver()->clear($selector);
            $this->metrics?->inc('cache_invalidate_hierarchical');
        }
    }

    public function invalidateExact(KeyInterface|array $keys, SyncMode $mode = SyncMode::ASYNC): void
    {
        foreach ((array)$keys as $key) {
            if ($mode === SyncMode::ASYNC) {
                $this->eventDispatcher->dispatch(new AsyncEvent($key, true));
                return;
            }

            $this->pool->getDriver()->clear($key, true);
            $this->metrics?->inc('cache_invalidate');
        }
    }

    public function refresh(KeyInterface|array $keys, SyncMode $mode = SyncMode::ASYNC): void
    {
        foreach ((array)$keys as $key) {
            if ($mode === SyncMode::ASYNC) {
                $this->eventDispatcher->dispatch(new AsyncEvent($key));
                return;
            }

            $this->put($key, $this->loader->resolve($key));
        }
    }

    public function asPool(): \Psr\Cache\CacheItemPoolInterface
    {
        return $this->pool;
    }
}
