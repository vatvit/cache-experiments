<?php

namespace Cache;

use Cache\Interface\InvalidationInterface;
use Cache\Interface\KeyInterface;
use Cache\Interface\KeyPrefixInterface;

final class DefaultInvalidation implements InvalidationInterface
{
    public function __construct(private \Psr\Cache\CacheItemPoolInterface $pool)
    {
    }

    public function hierarchical(KeyPrefixInterface|KeyInterface $selector, InvalidateMode $mode): void
    {
        // Intentionally left minimal: integrate with Stash hierarchical clear or namespace versioning.
        // This stub exists to keep builder free of anonymous classes.
    }

    public function exact(KeyInterface $key, InvalidateMode $mode): void
    {
        // Minimal exact delete; refresh modes would enqueue warm-jobs in real integration.
        if ($mode === InvalidateMode::DELETE_SYNC || $mode === InvalidateMode::DEFAULT) {
            $this->pool->deleteItem($key->toString());
        } else {
            // Async delete/refresh would be delegated to a queue-backed implementation.
        }
    }
}
