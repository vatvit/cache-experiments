<?php

namespace Cache;

use Cache\Interface\CacheInterface;
use Cache\Interface\PsrPoolAccessInterface;
use Cache\Interface\KeyInterface;
use Cache\Interface\LoaderInterface;
use Cache\Interface\JitterInterface;
use Cache\Interface\InvalidationInterface;
use Cache\Interface\KeyPrefixInterface;
use Cache\Interface\MetricsInterface;
use Cache\Interface\ValueResultInterface;
use Stash\Invalidation;
use Stash\Interfaces\PoolInterface as StashPoolInterface;

class Cache implements CacheInterface, PsrPoolAccessInterface
{
    public function __construct(
        private StashPoolInterface               $pool,
        private LoaderInterface                   $loader,
        private int                               $hardTtlSec,
        private int                               $precomputeSec,         // seconds BEFORE hard TTL to precompute (soft window)
        private JitterInterface                   $jitter,
        private InvalidationInterface             $invalidation,
        private ?MetricsInterface                 $metrics,
        private ?\Psr\Log\LoggerInterface         $logger
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

        $item->setInvalidationMethod(Invalidation::PRECOMPUTE, $this->precomputeSec);

        $val = $item->get();

        if ($item->isHit()) {
            // Stash exposes timestamps; PSR-6 wrappers may not — guard for nulls.
            $createdAt = method_exists($item, 'getCreation') && $item->getCreation()
                ? $item->getCreation()->getTimestamp() : time();
            $expiresAt = method_exists($item, 'getExpiration') && $item->getExpiration()
                ? $item->getExpiration()->getTimestamp() : ($createdAt + $this->hardTtlSec);

            // Soft boundary is "expiresAt - precomputeSec"
            $softAt = max($createdAt, $expiresAt - $this->precomputeSec);
            $now = time();

            if ($now < $softAt) {
                $this->metrics?->inc('cache_hit', ['state' => 'fresh']);
                return ValueResult::hit($val, $createdAt, $softAt);
            }

            // stale-while-revalidate: return old, initiate refresh
            $this->metrics?->inc('cache_hit', ['state' => 'stale']);
            $this->invalidation->exact($key, InvalidateMode::REFRESH_ASYNC);
            return ValueResult::stale($val, $createdAt, $softAt);
        }

        // Miss → load and save
        try {
            $loaded = $this->loader->resolve($key);
        } catch (\Throwable $e) {
            $this->logger?->error('cache.loader_failed', ['key' => (string)$key, 'e' => $e]);
            $this->metrics?->inc('cache_miss', ['cause' => 'loader_failed']);
            return ValueResult::miss();
        }

        $this->save($key, $loaded);
        $this->metrics?->inc('cache_fill');
        // After save we can reconstruct times deterministically
        $now = time();
        $hard = $now + $this->hardTtlSec;
        $soft = max($now, $hard - $this->precomputeSec);
        return ValueResult::hit($loaded, $now, $soft);
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

    public function invalidate(KeyPrefixInterface|KeyInterface|array $selectors, InvalidateMode $mode = InvalidateMode::DEFAULT): void
    {
        foreach ((array)$selectors as $sel) {
            $this->invalidation->hierarchical($sel, $mode);
        }
    }

    public function invalidateExact(KeyInterface|array $keys, InvalidateMode $mode = InvalidateMode::DEFAULT): void
    {
        foreach ((array)$keys as $k) {
            $this->invalidation->exact($k, $mode);
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
