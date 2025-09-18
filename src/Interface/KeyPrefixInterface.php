<?php

namespace Cache\Interface;

interface KeyPrefixInterface
{
    public function segments(): array;

    public function toString(): string;
}
