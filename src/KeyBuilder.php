<?php

namespace Cache;

use Cache\Interface\Key;

final class KeyBuilder
{
    private const string SEP = '/';

    private ?string $domain = null;
    private ?string $facet = null;
    private ?string $id = null;
    private ?string $schema = null;
    private ?string $locale = null;

    public function withDomain(string $domain): self
    {
        $this->domain = $this->norm($domain);
        return $this;
    }

    public function withFacet(string $facet): self
    {
        $this->facet = $this->norm($facet);
        return $this;
    }

    public function withId(string|int $id): self
    {
        $this->id = $this->norm((string)$id);
        return $this;
    }

    public function withSchemaVersion(?string $schemaVer): self
    {
        $this->schema = ($schemaVer === null || $schemaVer === '') ? null : $this->norm($schemaVer);
        return $this;
    }

    public function withLocale(?string $locale): self
    {
        $this->locale = ($locale === null || $locale === '') ? null : $this->norm($locale);
        return $this;
    }

    public function fromKey(Key $key): self
    {
        $this->domain = $this->norm($key->domain());
        $this->facet = $this->norm($key->facet());
        $this->id = $this->norm($key->id());
        $this->schema = ($key->schemaVersion() === null || $key->schemaVersion() === '') ? null : $this->norm($key->schemaVersion());
        $this->locale = ($key->locale() === null || $key->locale() === '') ? null : $this->norm($key->locale());
        return $this;
    }

    public function fromString(string $key): self
    {
        $parts = array_values(array_filter(explode(self::SEP, $key), static fn($s) => $s !== ''));
        $parts = array_map(fn($part) => rawurlencode($part), $parts);
        if (count($parts) < 3) {
            throw new \InvalidArgumentException("Invalid key format: '{$key}'");
        }
        $this->domain = $this->norm($parts[0]);
        $this->facet = $this->norm($parts[1]);
        $this->id = $this->norm($parts[count($parts) - 1]);
        // опциональные сегменты по позициям: [2]=schema, [3]=locale
        $this->schema = $parts[2] ?? null;
        $this->locale = $parts[3] ?? null;
        if ($this->schema !== null) $this->schema = $this->norm($this->schema);
        if ($this->locale !== null) $this->locale = $this->norm($this->locale);
        return $this;
    }

    public function build(): Key
    {
        if ($this->domain === null || $this->facet === null || $this->id === null) {
            throw new \LogicException('KeyBuilder: domain, facet and id are required.');
        }
        return new StashKey($this->domain, $this->facet, $this->id, $this->schema, $this->locale);
    }

    private function norm(string $s): string
    {
        $s = trim($s);
        if ($s === '') {
            throw new \InvalidArgumentException('Key segment must be non-empty.');
        }
        return $s;
    }
}
