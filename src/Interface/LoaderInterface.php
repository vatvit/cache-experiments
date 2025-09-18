<?php

namespace Cache\Interface;

interface LoaderInterface
{
    public function resolve(KeyInterface $key): mixed;
}
