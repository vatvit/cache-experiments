<?php

namespace Cache\Interface;

use Cache\SyncMode;

interface CacheInterface
{
    public function get(KeyInterface $key): ValueResultInterface;

    public function getMany(iterable $keys): \SplObjectStorage; // KeyInterface => ValueResultInterface

    public function put(KeyInterface $key, mixed $value): void;

    public function invalidate(KeyPrefixInterface|KeyInterface|array $selectors, SyncMode $mode = SyncMode::ASYNC): void;

    public function invalidateExact(KeyInterface|array $keys, SyncMode $mode = SyncMode::ASYNC): void;

    public function refresh(KeyInterface|array $keys, SyncMode $mode = SyncMode::ASYNC): void;
}
