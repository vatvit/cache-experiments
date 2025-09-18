<?php

namespace Cache\Interface;

interface MetricsInterface
{
    public function inc(string $name, array $labels = []): void;

    public function observe(string $name, float $value, array $labels = []): void;
}
