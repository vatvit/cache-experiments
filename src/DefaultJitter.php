<?php

namespace Cache;

use Cache\Interface\Jitter;
use Cache\Interface\Key;

final class DefaultJitter implements Jitter
{
    public function __construct(private int $percent = 15)
    {
    }

    public function apply(int $ttlSec, Key $key): int
    {
        // Deterministic jitter based on key hash in range [-delta, +delta].
        $delta = max(0, (int)floor($ttlSec * $this->percent / 100));
        if ($delta === 0) return max(1, $ttlSec);
        $h = crc32($key->toString());
        $offset = (int)($h % (2 * $delta + 1)) - $delta;
        return max(1, $ttlSec + $offset);
    }
}
