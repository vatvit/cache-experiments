<?php

namespace Cache;

use Cache\Interface\Loader;
use Cache\Interface\Key;

final class CallableLoader implements Loader
{
    /** @param callable(Key):mixed $fn */
    public function __construct(private \Closure $fn)
    {
    }

    public function resolve(Key $key): mixed
    {
        return ($this->fn)($key);
    }
}
