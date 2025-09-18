<?php

namespace Cache\Interface;

use Cache\InvalidateMode;

interface InvalidationInterface
{
    /** Execute hierarchical invalidation for prefix/full key according to mode. */
    public function hierarchical(KeyPrefixInterface|KeyInterface $selector, InvalidateMode $mode): void;

    /** Execute exact invalidation for a specific key according to mode. */
    public function exact(KeyInterface $key, InvalidateMode $mode): void;
}

