# Advanced Cache Library

A high-performance PHP cache library with stale-while-revalidate pattern, async operations, and cache stampede prevention.

## Features

- **Stale-while-revalidate**: Serve stale data while computing fresh values in background
- **Cache stampede prevention**: Built-in jitter and leader/follower pattern
- **Async operations**: Non-blocking cache invalidation and refresh
- **Structured keys**: Domain-based cache keys with versioning and localization
- **Redis backend**: Built on top of Stash cache with Redis support
- **Batch operations**: Efficient bulk get/invalidate operations
- **Flexible invalidation**: Exact key or prefix-based invalidation strategies

## Installation

```bash
composer require vendor/package-name
```

## Usage

```php
<?php

use Cache\Cache;
use Cache\Key;
use Cache\CallableLoader;
use Cache\DefaultJitter;
use Stash\Pool;
use Stash\Driver\Redis;

// Setup Redis and cache
$redis = new \Redis();
$driver = new Redis(['connection' => $redis]);
$pool = new Pool($driver);

$cache = new Cache(
    $pool,
    new CallableLoader(function($key) { /* your loader logic */ }),
    $hardTtlSec = 3600,      // Hard TTL
    $precomputeSec = 60,     // Precompute window
    new DefaultJitter(15)    // Jitter for stampede prevention
);

// Create structured cache keys
$key = new Key(
    domain: 'product',
    facet: 'top-sellers',
    id: ['category' => 456, 'price' => 1000],
    version: 2,
    locale: 'en'
);

// Get cached value
$result = $cache->get($key);

$cacheProduct->refresh($key); // async by default

$cacheProduct->put($key, $value);

$cacheProduct->invalidateExact($key); // invalidate exact key only. do not invalidate hierarchical keys.

$cacheProduct->invalidate($key, \Cache\SyncMode::SYNC);

```

## Testing

```bash
composer test
```

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security

If you discover any security related issues, please email author@example.com instead of using the issue tracker.

## Credits

- [Author Name](https://github.com/authorusername)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
