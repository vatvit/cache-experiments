<?php

namespace Cache\Interface;

use Cache\InvalidateMode;

interface CacheInterface
{
    public function get(KeyInterface $key): ValueResultInterface;

    public function getMany(iterable $keys): \SplObjectStorage; // KeyInterface => ValueResultInterface

    public function put(KeyInterface $key, mixed $value): void;

    public function invalidate(KeyPrefixInterface|KeyInterface|array $selectors, InvalidateMode $mode = InvalidateMode::DEFAULT): void;

    public function invalidateExact(KeyInterface|array $keys, InvalidateMode $mode = InvalidateMode::DEFAULT): void;
}
