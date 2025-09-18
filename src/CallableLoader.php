<?php

namespace Cache;

use Cache\Interface\KeyInterface;
use Cache\Interface\LoaderInterface;

final class CallableLoader implements LoaderInterface
{
    public function resolve(KeyInterface $key): mixed
    {
        return ($this->fn)($key);
    }
}
