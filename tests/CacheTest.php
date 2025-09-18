<?php

declare(strict_types=1);

namespace Cache\Tests;

use Cache\Cache;
use Cache\CacheReadState;
use Cache\InvalidateMode;
use Cache\ValueResult;
use Cache\Interface\CacheInterface;
use Cache\Interface\InvalidationInterface;
use Cache\Interface\JitterInterface;
use Cache\Interface\KeyInterface;
use Cache\Interface\KeyPrefixInterface;
use Cache\Interface\LoaderInterface;
use Cache\Interface\MetricsInterface;
use Cache\Interface\PsrPoolAccessInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Stash\Interfaces\PoolInterface as StashPoolInterface;
use Stash\Interfaces\ItemInterface as StashItemInterface;

class CacheTest extends TestCase
{
    private MockObject $pool;
    private MockObject $loader;
    private MockObject $jitter;
    private MockObject $invalidation;
    private MockObject $metrics;
    private MockObject $logger;
    private MockObject $key;
    private MockObject $cacheItem;

    protected function setUp(): void
    {
        $this->pool = $this->createMock(StashPoolInterface::class);
        $this->loader = $this->createMock(LoaderInterface::class);
        $this->jitter = $this->createMock(JitterInterface::class);
        $this->invalidation = $this->createMock(InvalidationInterface::class);
        $this->metrics = $this->createMock(MetricsInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->key = $this->createMock(KeyInterface::class);
        $this->cacheItem = $this->createMock(StashItemInterface::class);
    }

    private function createCache(
        int $hardTtlSec = 3600,
        int $precomputeSec = 300,
        ?MetricsInterface $metrics = null,
        ?LoggerInterface $logger = null
    ): Cache {
        return new Cache(
            $this->pool,
            $this->loader,
            $hardTtlSec,
            $precomputeSec,
            $this->jitter,
            $this->invalidation,
            $metrics ?? $this->metrics,
            $logger ?? $this->logger
        );
    }

    public function testConstructorValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hardTtlSec must be >= 1');

        new Cache(
            $this->pool,
            $this->loader,
            0, // Invalid hardTtlSec
            300,
            $this->jitter,
            $this->invalidation,
            $this->metrics,
            $this->logger
        );
    }

    public function testConstructorPrecomputeSecValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('precomputeSec must be in [0, hardTtlSec]');

        new Cache(
            $this->pool,
            $this->loader,
            3600,
            3700, // precomputeSec > hardTtlSec
            $this->jitter,
            $this->invalidation,
            $this->metrics,
            $this->logger
        );
    }

    public function testConstructorNegativePrecomputeSecValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('precomputeSec must be in [0, hardTtlSec]');

        new Cache(
            $this->pool,
            $this->loader,
            3600,
            -1, // Negative precomputeSec
            $this->jitter,
            $this->invalidation,
            $this->metrics,
            $this->logger
        );
    }

    public function testImplementsInterfaces(): void
    {
        $cache = $this->createCache();

        $this->assertInstanceOf(CacheInterface::class, $cache);
        $this->assertInstanceOf(PsrPoolAccessInterface::class, $cache);
    }

    public function testGetHitFresh(): void
    {
        $now = time();
        $createdAt = $now - 1000;
        $expiresAt = $now + 2000;
        $softAt = $expiresAt - 300; // Still in fresh window

        $this->key->expects($this->once())
            ->method('toString')
            ->willReturn('test-key');

        $this->pool->expects($this->once())
            ->method('getItem')
            ->with('test-key')
            ->willReturn($this->cacheItem);

        $this->cacheItem->expects($this->once())
            ->method('setInvalidationMethod')
            ->with(\Stash\Invalidation::PRECOMPUTE, 300);

        $this->cacheItem->expects($this->once())
            ->method('get')
            ->willReturn('cached-value');

        $this->cacheItem->expects($this->once())
            ->method('isHit')
            ->willReturn(true);

        $this->cacheItem->expects($this->exactly(2))
            ->method('getCreation')
            ->willReturn(new \DateTime('@' . $createdAt));

        $this->cacheItem->expects($this->exactly(2))
            ->method('getExpiration')
            ->willReturn(new \DateTime('@' . $expiresAt));

        $this->metrics->expects($this->once())
            ->method('inc')
            ->with('cache_hit', ['state' => 'fresh']);

        $cache = $this->createCache();
        $result = $cache->get($this->key);

        $this->assertTrue($result->isHit());
        $this->assertFalse($result->isStale());
        $this->assertFalse($result->isMiss());
        $this->assertEquals('cached-value', $result->value());
        $this->assertEquals($createdAt, $result->createdAt());
    }

    public function testGetHitStale(): void
    {
        $now = time();
        $createdAt = $now - 4000;
        $expiresAt = $now + 100;
        $softAt = $expiresAt - 300; // Already past soft window

        $this->key->expects($this->once())
            ->method('toString')
            ->willReturn('test-key');

        $this->pool->expects($this->once())
            ->method('getItem')
            ->with('test-key')
            ->willReturn($this->cacheItem);

        $this->cacheItem->expects($this->once())
            ->method('setInvalidationMethod')
            ->with(\Stash\Invalidation::PRECOMPUTE, 300);

        $this->cacheItem->expects($this->once())
            ->method('get')
            ->willReturn('cached-value');

        $this->cacheItem->expects($this->once())
            ->method('isHit')
            ->willReturn(true);

        $this->cacheItem->expects($this->exactly(2))
            ->method('getCreation')
            ->willReturn(new \DateTime('@' . $createdAt));

        $this->cacheItem->expects($this->exactly(2))
            ->method('getExpiration')
            ->willReturn(new \DateTime('@' . $expiresAt));

        $this->metrics->expects($this->once())
            ->method('inc')
            ->with('cache_hit', ['state' => 'stale']);

        $this->invalidation->expects($this->once())
            ->method('exact')
            ->with($this->key, InvalidateMode::REFRESH_ASYNC);

        $cache = $this->createCache();
        $result = $cache->get($this->key);

        $this->assertFalse($result->isHit());
        $this->assertTrue($result->isStale());
        $this->assertFalse($result->isMiss());
        $this->assertEquals('cached-value', $result->value());
        $this->assertEquals($createdAt, $result->createdAt());
    }

    public function testGetMiss(): void
    {
        $this->key->expects($this->exactly(2))
            ->method('toString')
            ->willReturn('test-key');

        $this->pool->expects($this->exactly(2))
            ->method('getItem')
            ->with('test-key')
            ->willReturn($this->cacheItem);

        $this->cacheItem->expects($this->once())
            ->method('setInvalidationMethod')
            ->with(\Stash\Invalidation::PRECOMPUTE, 300);

        $this->cacheItem->expects($this->once())
            ->method('get')
            ->willReturn(null);

        $this->cacheItem->expects($this->once())
            ->method('isHit')
            ->willReturn(false);

        $this->loader->expects($this->once())
            ->method('resolve')
            ->with($this->key)
            ->willReturn('loaded-value');

        // For save() method
        $this->jitter->expects($this->once())
            ->method('apply')
            ->with(3600, $this->key)
            ->willReturn(3600);

        $this->cacheItem->expects($this->once())
            ->method('set')
            ->with('loaded-value');

        $this->cacheItem->expects($this->once())
            ->method('expiresAfter')
            ->with(3600);

        $this->pool->expects($this->once())
            ->method('save')
            ->with($this->cacheItem);

        $this->metrics->expects($this->once())
            ->method('inc')
            ->with('cache_fill');

        $cache = $this->createCache();
        $result = $cache->get($this->key);

        $this->assertTrue($result->isHit());
        $this->assertFalse($result->isStale());
        $this->assertFalse($result->isMiss());
        $this->assertEquals('loaded-value', $result->value());
    }

    public function testGetMissLoaderFailure(): void
    {
        $this->key->expects($this->once())
            ->method('toString')
            ->willReturn('test-key');

        $this->pool->expects($this->once())
            ->method('getItem')
            ->with('test-key')
            ->willReturn($this->cacheItem);

        $this->cacheItem->expects($this->once())
            ->method('setInvalidationMethod')
            ->with(\Stash\Invalidation::PRECOMPUTE, 300);

        $this->cacheItem->expects($this->once())
            ->method('get')
            ->willReturn(null);

        $this->cacheItem->expects($this->once())
            ->method('isHit')
            ->willReturn(false);

        $exception = new \RuntimeException('Loader failed');
        $this->loader->expects($this->once())
            ->method('resolve')
            ->with($this->key)
            ->willThrowException($exception);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('cache.loader_failed', $this->callback(function ($context) use ($exception) {
                return isset($context['key']) & isset($context['e']) && $context['e'] === $exception;
            }));

        $this->metrics->expects($this->once())
            ->method('inc')
            ->with('cache_miss', ['cause' => 'loader_failed']);

        $cache = $this->createCache();
        $result = $cache->get($this->key);

        $this->assertFalse($result->isHit());
        $this->assertFalse($result->isStale());
        $this->assertTrue($result->isMiss());
    }

    public function testGetMany(): void
    {
        $key1 = $this->createMock(KeyInterface::class);
        $key2 = $this->createMock(KeyInterface::class);
        $keys = [$key1, $key2];

        $key1->method('toString')->willReturn('key1');
        $key2->method('toString')->willReturn('key2');

        $item1 = $this->createMock(CacheItemInterface::class);
        $item2 = $this->createMock(CacheItemInterface::class);

        $this->pool->method('getItem')
            ->willReturnMap([
                ['key1', $item1],
                ['key2', $item2]
            ]);

        $item1->method('isHit')->willReturn(false);
        $item2->method('isHit')->willReturn(false);

        $this->loader->method('resolve')
            ->willReturnMap([
                [$key1, 'value1'],
                [$key2, 'value2']
            ]);

        $cache = $this->createCache();
        $results = $cache->getMany($keys);

        $this->assertInstanceOf(\SplObjectStorage::class, $results);
        $this->assertEquals(2, $results->count());
        $this->assertTrue($results->contains($key1));
        $this->assertTrue($results->contains($key2));
    }

    public function testPut(): void
    {
        $this->key->expects($this->once())
            ->method('toString')
            ->willReturn('test-key');

        $this->pool->expects($this->once())
            ->method('getItem')
            ->with('test-key')
            ->willReturn($this->cacheItem);

        $this->jitter->expects($this->once())
            ->method('apply')
            ->with(3600, $this->key)
            ->willReturn(3600);

        $this->cacheItem->expects($this->once())
            ->method('set')
            ->with('test-value');

        $this->cacheItem->expects($this->once())
            ->method('expiresAfter')
            ->with(3600);

        $this->pool->expects($this->once())
            ->method('save')
            ->with($this->cacheItem);

        $this->metrics->expects($this->once())
            ->method('inc')
            ->with('cache_put');

        $cache = $this->createCache();
        $cache->put($this->key, 'test-value');
    }

    public function testPutWithJitter(): void
    {
        $this->key->expects($this->once())
            ->method('toString')
            ->willReturn('test-key');

        $this->pool->expects($this->once())
            ->method('getItem')
            ->with('test-key')
            ->willReturn($this->cacheItem);

        $this->jitter->expects($this->once())
            ->method('apply')
            ->with(3600, $this->key)
            ->willReturn(3650); // Jittered TTL

        $this->cacheItem->expects($this->once())
            ->method('set')
            ->with('test-value');

        $this->cacheItem->expects($this->once())
            ->method('expiresAfter')
            ->with(3650); // Jittered value

        $this->pool->expects($this->once())
            ->method('save')
            ->with($this->cacheItem);

        $this->metrics->expects($this->once())
            ->method('inc')
            ->with('cache_put');

        $cache = $this->createCache();
        $cache->put($this->key, 'test-value');
    }

    public function testInvalidateWithSingleKey(): void
    {
        $key = $this->createMock(KeyPrefixInterface::class)->expects($this->once());
        $this->invalidation->expects($this->once())
            ->method('hierarchical')
            ->with($key, InvalidateMode::DEFAULT);

        $cache = $this->createCache();
        $cache->invalidate($key);
    }

    public function testInvalidateWithArray(): void
    {
        $key1 = $this->createMock(KeyPrefixInterface::class)->expects($this->once());
        $key2 = $this->createMock(KeyPrefixInterface::class)->expects($this->once());
        $keys = [$key1, $key2];

        $callCount = 0;
        $this->invalidation->expects($this->exactly(2))
            ->method('hierarchical')
            ->willReturnCallback(function($selector, $mode) use ($key1, $key2, &$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    $this->assertSame($key1, $selector);
                } else {
                    $this->assertSame($key2, $selector);
                }
                $this->assertEquals(InvalidateMode::DEFAULT, $mode);
            });

        $cache = $this->createCache();
        $cache->invalidate($keys);
    }

    public function testInvalidateWithCustomMode(): void
    {
        $key = $this->createMock(KeyPrefixInterface::class)->expects($this->once());
        $this->invalidation->expects($this->once())
            ->method('hierarchical')
            ->with($key, InvalidateMode::REFRESH_ASYNC);

        $cache = $this->createCache();
        $cache->invalidate($key, InvalidateMode::REFRESH_ASYNC);
    }

    public function testInvalidateExactWithSingleKey(): void
    {
        $this->invalidation->expects($this->once())
            ->method('exact')
            ->with($this->key, InvalidateMode::DEFAULT);

        $cache = $this->createCache();
        $cache->invalidateExact($this->key);
    }

    public function testInvalidateExactWithArray(): void
    {
        $key1 = $this->createMock(KeyInterface::class);
        $key2 = $this->createMock(KeyInterface::class);
        $keys = [$key1, $key2];

        $callCount = 0;
        $this->invalidation->expects($this->exactly(2))
            ->method('exact')
            ->willReturnCallback(function($key, $mode) use ($key1, $key2, &$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    $this->assertSame($key1, $key);
                } else {
                    $this->assertSame($key2, $key);
                }
                $this->assertEquals(InvalidateMode::DEFAULT, $mode);
            });

        $cache = $this->createCache();
        $cache->invalidateExact($keys);
    }

    public function testInvalidateExactWithCustomMode(): void
    {
        $this->invalidation->expects($this->once())
            ->method('exact')
            ->with($this->key, InvalidateMode::REFRESH_ASYNC);

        $cache = $this->createCache();
        $cache->invalidateExact($this->key, InvalidateMode::REFRESH_ASYNC);
    }

    public function testAsPool(): void
    {
        $cache = $this->createCache();
        $pool = $cache->asPool();

        $this->assertSame($this->pool, $pool);
        $this->assertInstanceOf(CacheItemPoolInterface::class, $pool);
    }

    public function testGetWithNullMetrics(): void
    {
        $this->key->method('toString')->willReturn('test-key');
        $this->pool->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(false);
        $this->loader->method('resolve')->willReturn('loaded-value');

        // Create cache with null metrics - should not throw
        $cache = $this->createCache(metrics: null);
        $result = $cache->get($this->key);

        $this->assertTrue($result->isHit());
    }

    public function testGetWithNullLogger(): void
    {
        $this->key->method('toString')->willReturn('test-key');
        $this->pool->method('getItem')->willReturn($this->cacheItem);
        $this->cacheItem->method('isHit')->willReturn(false);

        $exception = new \RuntimeException('Loader failed');
        $this->loader->method('resolve')->willThrowException($exception);

        // Create cache with null logger - should not throw
        $cache = $this->createCache(logger: null);
        $result = $cache->get($this->key);

        $this->assertTrue($result->isMiss());
    }

    public function testGetHitWithoutTimestampMethods(): void
    {
        // Test with cache item that doesn't have getCreation/getExpiration methods
        $basicItem = $this->createMock(CacheItemInterface::class);

        $this->key->method('toString')->willReturn('test-key');
        $this->pool->method('getItem')->willReturn($basicItem);

        $basicItem->method('isHit')->willReturn(true);
        $basicItem->method('get')->willReturn('cached-value');
        $basicItem->method('setInvalidationMethod')->willReturn(true);

        $this->metrics->expects($this->once())
            ->method('inc')
            ->with('cache_hit', ['state' => 'fresh']);

        $cache = $this->createCache();
        $result = $cache->get($this->key);

        $this->assertTrue($result->isHit());
        $this->assertEquals('cached-value', $result->value());
    }
}
