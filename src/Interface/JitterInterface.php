<?php

namespace Cache\Interface;

interface JitterInterface
{
    public function apply(int $ttlSec, KeyInterface $key): int;
}
