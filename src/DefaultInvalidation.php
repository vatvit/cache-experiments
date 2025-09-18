<?php

final class DefaultInvalidation implements Invalidation
{
    public function __construct(private \Psr\Cache\CacheItemPoolInterface $pool)
    {
    }

    public function hierarchical(KeyPrefix|Key $selector, InvalidateMode $mode): void
    {
        // Intentionally left minimal: integrate with Stash hierarchical clear or namespace versioning.
        // This stub exists to keep builder free of anonymous classes.
    }

    public function exact(Key $key, InvalidateMode $mode): void
    {
        // Minimal exact delete; refresh modes would enqueue warm-jobs in real integration.
        if ($mode === InvalidateMode::DELETE_SYNC || $mode === InvalidateMode::DEFAULT) {
            $this->pool->deleteItem($key->toString());
        } else {
            // Async delete/refresh would be delegated to a queue-backed implementation.
        }
    }
}
