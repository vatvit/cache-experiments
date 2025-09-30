<?php

use Cache\CallableLoader;

$redis = new \Redis();

$myRedisDriver = new \Stash\Driver\Redis(['connection' => $redis]);

$stashPool = new \Stash\Pool($myRedisDriver);
$stashPool->setItemClass(\Cache\MyItem::class);

$cacheProduct = new \Cache\Cache(
    $stashPool,
    new CallableLoader(function ($key) {}),
    $hardTtlSec = 3600,
    $precomputeSec = 60,         // seconds BEFORE hard TTL to precompute (soft window)
    new \Cache\DefaultJitter(15)
);

// Usage example

$domain = 'product';
$facet = 'top-sellers';
$id = ['category' => 456, 'price' => 1000, 'brand' => 'Apple'];
$version = 2;
$locale = 'en';
$key = new \Cache\Key($domain, $facet, $id, $version, $locale);
$value = $cacheProduct->get($key);

$cacheProduct->refresh($key); // async by default

$cacheProduct->put($key, $value);

$cacheProduct->invalidateExact($key); // invalidate exact key only. do not invalidate hierarchical keys.

$cacheProduct->invalidate($key, \Cache\SyncMode::SYNC);
