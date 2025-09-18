<?php

namespace Cache\Interface;

interface PsrPoolAccessInterface
{
    public function asPool(): \Psr\Cache\CacheItemPoolInterface;
}
