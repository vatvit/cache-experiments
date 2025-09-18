<?php

namespace Cache;

final class InvalidatePolicy
{
    public function __construct(
        private InvalidateMode $mode = InvalidateMode::DELETE_ASYNC,
        private bool           $cascadeNamespaces = false
    )
    {
    }

    public static function create(InvalidateMode $m): self
    {
        return new self($m);
    }

    public function mode(): InvalidateMode
    {
        return $this->mode;
    }

    public function cascadeNamespaces(): bool
    {
        return $this->cascadeNamespaces;
    }

    public function withMode(InvalidateMode $m): self
    {
        $n = clone $this;
        $n->mode = $m;
        return $n;
    }

    public function withCascade(bool $v): self
    {
        $n = clone $this;
        $n->cascadeNamespaces = $v;
        return $n;
    }
}
