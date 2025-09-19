<?php

namespace Cache;

use Cache\Interface\KeyInterface;

class InvalidationEvent extends \stdClass
{
    public function __construct(
        public KeyInterface $key,
        public InvalidateMode $mode,
        public bool $exact = false,
    )
    {

    }
}
