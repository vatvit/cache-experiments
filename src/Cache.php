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
        private StashPoolInterface        $pool,
        private LoaderInterface           $loader,
        private int                       $hardTtlSec,
        private int                       $precomputeSec,         // seconds BEFORE hard TTL to precompute (soft window)
        private JitterInterface           $jitter,
        private EventDispatcherInterface|null  $eventDispatcher = null,
        private MetricsInterface|null         $metrics = null,
        private \Psr\Log\LoggerInterface|null $logger = null,
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

        // 1) precompute: start regeneration pre-expiry; isHit() becomes false inside the soft window
        $item->setInvalidationMethod(Invalidation::PRECOMPUTE, $this->precomputeSec);

        $value = $item->get();
        if ($item->isHit()) {
            [$createdAt, $softAt] = $this->timestampsFromItem($item);
            $this->metrics?->inc('cache_hit', ['state' => 'fresh']);
            return ValueResult::hit($value, $createdAt, $softAt);
        }

        // 2) single-flight: try to become the leader
        $won = $item->lock();
        if ($won) {
            try {
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
            } finally {
                // lock is released when $item is out of scope (Stash frees it with Item lifecycle)
                unset($item);
            }
        }

        // 3) follower path: someone else is rebuilding — serve stale or briefly wait
        // a) try to serve STALE while leader holds the lock
        $item->setInvalidationMethod(Invalidation::OLD); // serve previous value if locked by another process
        $stale = $item->get();
        if ($stale !== null) {
            [$createdAt, $softAt] = $this->timestampsFromItem($item);
            $this->metrics?->inc('cache_hit', ['state' => 'stale']);
            return ValueResult::stale($stale, $createdAt, $softAt);
        }

        // b) optional: short wait for leader to finish (CLI/background-safe)
        $item->setInvalidationMethod(Invalidation::SLEEP, 150, 6); // 6x150ms = ~900ms max wait
        $waited = $item->get();
        if ($item->isHit()) {
            [$createdAt, $softAt] = $this->timestampsFromItem($item);
            $this->metrics?->inc('cache_hit', ['state' => 'fresh_after_sleep']);
            return ValueResult::hit($waited, $createdAt, $softAt);
        }

        // c) last resort: fail-open compute (do NOT save to avoid double-write), or miss if you prefer fail-closed
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

    public function invalidate(KeyPrefixInterface|KeyInterface|array $selectors, SyncMode $mode = SyncMode::ASYNC): void
    {
        foreach ((array)$selectors as $selector) {
            if ($mode === SyncMode::ASYNC) {
                $this->eventDispatcher->dispatch(new InvalidationEvent($selector, $mode, false));
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
                $this->eventDispatcher->dispatch(new InvalidationEvent($key, $mode, true));
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
                $this->eventDispatcher->dispatch(new InvalidationEvent($key, $mode, true));
                return;
            }

            $this->put($key, $this->loader->resolve($key));
        }
    }

    public function asPool(): \Psr\Cache\CacheItemPoolInterface
    {
        return $this->pool;
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
}
