<?php

interface ProductProvider
{
    public function byId(int $id): array;

    public function info(int $id): array;

    public function description(int $id): string;
}

final class CatalogCache
{
    public function __construct(
        private CacheEngine       $engine,          // единый движок нашей либы (SWR, коалесинг, Redis)
        private KeyBuilder        $keys,             // общий/кастомный билдер ключей
        private ProductProvider   $provider,    // как получить данные (сырой источник)
        // доменные дефолты (опционально; можно передавать null и полагаться на дефолты движка)
        private ?GetPolicy        $getDefault = null,
        private ?InvalidatePolicy $invDefault = null,
    )
    {
    }

    // ГОРЯЧИЙ путь: короткое soft-окно, async refresh
    public function getProduct(int $id, ?GetPolicy $policy = null): array
    {
        $key = $this->keys->build('catalog', 'product', $id, schemaVer: 'v2');
        $p = $policy
            ?? $this->getDefault
            ?? GetPolicy::create(hardSec: 86400, softSec: 300)->withRefreshMode(RefreshMode::ASYNC);

        return $this->engine
            ->get($key, loader: fn() => $this->provider->byId($id), policy: $p)
            ->value();
    }

    // БОЛЕЕ СПОКОЙНЫЙ путь: длиннее TTL, можно оставить ASYNC
    public function getProductInfo(int $id, ?GetPolicy $policy = null): array
    {
        $key = $this->keys->build('catalog', 'product_info', $id, 'v2');
        $base = $this->getDefault ?? GetPolicy::create(86400, 600);
        $p = $policy ?? $base->withSoftTtl(900); // уточнение только для этого метода

        return $this->engine
            ->get($key, loader: fn() => $this->provider->info($id), policy: $p)
            ->value();
    }

    // ХОЛОДНЫЙ путь: длинный TTL, можно sync для консистентности текста
    public function getProductDescription(int $id, ?GetPolicy $policy = null): string
    {
        $key = $this->keys->build('catalog', 'product_desc', $id, 'v2');
        $base = $this->getDefault ?? GetPolicy::create(604800, 3600); // 7d hard, 1h soft
        $p = $policy ?? $base->withRefreshMode(RefreshMode::SYNC);

        return $this->engine
            ->get($key, loader: fn() => $this->provider->description($id), policy: $p)
            ->value();
    }

    // Инвалидация по id (по умолчанию REFRESH, чтобы не создавать лавину miss)
    public function invalidateProduct(int $id, ?InvalidatePolicy $policy = null): void
    {
        $key = $this->keys->build('catalog', 'product', $id, 'v2');
        $p = $policy
            ?? $this->invDefault
            ?? InvalidatePolicy::create(InvalidateMode::REFRESH);

        $this->engine->invalidate($key, $p);
    }

    // Инвалидация по фасету целиком (версионный bump неймспейса)
    public function bumpProductNamespace(): void
    {
        $ns = $this->keys->build('catalog', 'product', 'ns', 'v2')->namespaceId();
        $this->engine->bumpNamespace($ns, $this->invDefault);
    }

    // Прямой доступ к источнику без кэша
    public function raw(): ProductProvider
    {
        return $this->provider;
    }
}
