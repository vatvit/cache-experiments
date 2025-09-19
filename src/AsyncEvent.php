<?php

namespace Cache;

use Cache\Interface\KeyInterface;

class AsyncEvent
{
    public function __construct(
        public KeyInterface $key,
        public bool $exact = false,
    )
    {

    }
}
