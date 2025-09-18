<?php

namespace Cache\Interface;

interface KeyInterface
{
    public function toString(): string;              // storage-ready: "prefix/idString"

    public function __toString(): string;

    public function domain(): string;

    public function facet(): string;

    public function schemaVersion(): ?string;

    public function locale(): ?string;

    public function id(): string|array;              // original id as provided

    public function idString(): string;              // deterministic, separator-safe id

    public function prefixString(): string;          // encoded "domain/facet[/schema][/locale]"

    public function segments(): array;               // [domain, facet, (schema), (locale), idString]

    public function prefixSegments(): array;         // [domain, facet, (schema), (locale)]
}
