<?php

declare(strict_types=1);

namespace Cache\Tests;

use Cache\Cache;
use Cache\Interface\KeyInterface;
use Cache\Interface\LoaderInterface;
use Cache\Interface\JitterInterface;
use Cache\Interface\MetricsInterface;
use Cache\SyncMode;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Stash\Interfaces\ItemInterface;
use Stash\Interfaces\PoolInterface as StashPoolInterface;
use Stash\Interfaces\DriverInterface as StashDriverInterface;
use Stash\Invalidation;

final class CacheTest extends TestCase
{
    private function newKey(string $id = 'k'): KeyInterface
    {
        $key = $this->createMock(KeyInterface::class);
        $key->method('toString')->willReturn($id);
        return $key;
    }

    private function newDate(int $ts): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->setTimestamp($ts);
    }

    public function testGetFreshHit(): void
    {
        $pool = $this->createMock(StashPoolInterface::class);
        $item = $this->createMock(ItemInterface::class);
        $key = $this->newKey('fresh');

        // Fresh hit path
        $pool->method('getItem')->with('fresh')->willReturn($item);

        $item->expects($this->once())
            ->method('setInvalidationMethod')
            ->with(Invalidation::PRECOMPUTE, 60);

        $item->method('get')->willReturn('value');
        $item->method('isHit')->willReturn(true);
        $item->method('getCreation')->willReturn($this->newDate(1_000));
        $item->method('getExpiration')->willReturn($this->newDate(1_600));

        $loader = $this->createMock(LoaderInterface::class);
        $jitter = $this->createMock(JitterInterface::class);

        $cache = new Cache($pool, $loader, 600, 60, $jitter);
        $res = $cache->get($key);

        $this->assertTrue($res->isHit());
        $this->assertSame('value', $res->value());
        $this->assertSame(1_000, $res->createdAt());
        $this->assertSame(1_600 - 60, $res->softExpiresAt());
    }

    public function testLeaderComputeAndSaveOnMissWithLock(): void
    {
        $pool = $this->createMock(StashPoolInterface::class);
        $itemInitial = $this->createMock(ItemInterface::class);
        $itemForSave = $this->createMock(ItemInterface::class);
        $key = $this->newKey('leader');

        // First getItem -> initial miss item
        $pool->expects($this->exactly(2))
            ->method('getItem')
            ->withConsecutive(['leader'], ['leader'])
            ->willReturnOnConsecutiveCalls($itemInitial, $itemForSave);

        // Fast path miss
        $itemInitial->expects($this->once())
            ->method('setInvalidationMethod')
            ->with(Invalidation::PRECOMPUTE, 60);
        $itemInitial->method('get')->willReturn(null);
        $itemInitial->method('isHit')->willReturn(false);

        // Become leader
        $itemInitial->expects($this->once())->method('lock')->willReturn(true);

        // Save path
        $itemForSave->expects($this->once())->method('set')->with('loaded');
        $itemForSave->expects($this->once())->method('expiresAfter')->with(600);
        $pool->expects($this->once())->method('save')->with($itemForSave);

        $loader = $this->createMock(LoaderInterface::class);
        $loader->expects($this->once())->method('resolve')->with($key)->willReturn('loaded');

        $jitter = $this->createMock(JitterInterface::class);
        $jitter->method('apply')->with(600, $key)->willReturn(600);

        $cache = new Cache($pool, $loader, 600, 60, $jitter);
        $res = $cache->get($key);

        $this->assertTrue($res->isHit());
        $this->assertSame('loaded', $res->value());
    }

    public function testFollowerServeStaleWhenLockedByAnother(): void
    {
        $pool = $this->createMock(StashPoolInterface::class);
        $itemInitial = $this->createMock(ItemInterface::class);
        $itemStale = $this->createMock(ItemInterface::class);
        $key = $this->newKey('stale');

        // First getItem -> initial miss item
        $pool->expects($this->exactly(2))
            ->method('getItem')
            ->withConsecutive(['stale'], ['stale'])
            ->willReturnOnConsecutiveCalls($itemInitial, $itemStale);

        $itemInitial->expects($this->once())
            ->method('setInvalidationMethod')
            ->with(Invalidation::PRECOMPUTE, 60);
        $itemInitial->method('get')->willReturn(null);
        $itemInitial->method('isHit')->willReturn(false);
        $itemInitial->expects($this->once())->method('lock')->willReturn(false);

        // Serve stale path
        $itemStale->expects($this->once())
            ->method('setInvalidationMethod')
            ->with(Invalidation::OLD);
        $itemStale->method('get')->willReturn('stale-value');
        $itemStale->method('getCreation')->willReturn($this->newDate(1_000));
        $itemStale->method('getExpiration')->willReturn($this->newDate(1_600));

        $loader = $this->createMock(LoaderInterface::class);
        $jitter = $this->createMock(JitterInterface::class);

        $cache = new Cache($pool, $loader, 600, 60, $jitter);
        $res = $cache->get($key);

        $this->assertTrue($res->isStale());
        $this->assertSame('stale-value', $res->value());
        $this->assertSame(1_000, $res->createdAt());
        $this->assertSame(1_600 - 60, $res->softExpiresAt());
    }

    public function testFollowerWaitFreshAfterShortSleep(): void
    {
        $pool = $this->createMock(StashPoolInterface::class);
        $itemInitial = $this->createMock(ItemInterface::class);
        $itemWait = $this->createMock(ItemInterface::class);
        $key = $this->newKey('sleep');

        // getItem calls: initial, then wait
        $pool->expects($this->exactly(2))
            ->method('getItem')
            ->withConsecutive(['sleep'], ['sleep'])
            ->willReturnOnConsecutiveCalls($itemInitial, $itemWait);

        $itemInitial->expects($this->once())
            ->method('setInvalidationMethod')
            ->with(Invalidation::PRECOMPUTE, 60);
        $itemInitial->method('get')->willReturn(null);
        $itemInitial->method('isHit')->willReturn(false);
        $itemInitial->expects($this->once())->method('lock')->willReturn(false);

        // Follower couldn't serve stale (null), so it waits
        $itemWait->expects($this->once())
            ->method('setInvalidationMethod')
            ->with(Invalidation::SLEEP, 150, 6);
        $itemWait->method('get')->willReturn('fresh-after-wait');
        $itemWait->method('isHit')->willReturn(true);
        $itemWait->method('getCreation')->willReturn($this->newDate(2_000));
        $itemWait->method('getExpiration')->willReturn($this->newDate(2_600));

        $loader = $this->createMock(LoaderInterface::class);
        $jitter = $this->createMock(JitterInterface::class);

        $cache = new Cache($pool, $loader, 600, 60, $jitter);
        $res = $cache->get($key);

        $this->assertTrue($res->isHit());
        $this->assertSame('fresh-after-wait', $res->value());
        $this->assertSame(2_000, $res->createdAt());
        $this->assertSame(2_600 - 60, $res->softExpiresAt());
    }

    public function testFailOpenComputeWhenLeaderNotAvailable(): void
    {
        $pool = $this->createMock(StashPoolInterface::class);
        $itemInitial = $this->createMock(ItemInterface::class);
        $itemStale = $this->createMock(ItemInterface::class);
        $itemWait = $this->createMock(ItemInterface::class);
        $key = $this->newKey('race');

        // Sequence: initial -> stale try -> wait try
        $pool->expects($this->exactly(3))
            ->method('getItem')
            ->withConsecutive(['race'], ['race'], ['race'])
            ->willReturnOnConsecutiveCalls($itemInitial, $itemStale, $itemWait);

        // Miss and can't lock
        $itemInitial->expects($this->once())
            ->method('setInvalidationMethod')
            ->with(Invalidation::PRECOMPUTE, 60);
        $itemInitial->method('get')->willReturn(null);
        $itemInitial->method('isHit')->willReturn(false);
        $itemInitial->expects($this->once())->method('lock')->willReturn(false);

        // No stale available
        $itemStale->expects($this->once())
            ->method('setInvalidationMethod')
            ->with(Invalidation::OLD);
        $itemStale->method('get')->willReturn(null);

        // Wait but still no hit
        $itemWait->expects($this->once())
            ->method('setInvalidationMethod')
            ->with(Invalidation::SLEEP, 150, 6);
        $itemWait->method('get')->willReturn(null);
        $itemWait->method('isHit')->willReturn(false);

        $loader = $this->createMock(LoaderInterface::class);
        $loader->expects($this->once())->method('resolve')->with($key)->willReturn('fallback');

        $jitter = $this->createMock(JitterInterface::class);

        $cache = new Cache($pool, $loader, 600, 60, $jitter);
        $res = $cache->get($key);

        $this->assertTrue($res->isHit(), 'Fail-open should produce a hit-like result with computed value');
        $this->assertSame('fallback', $res->value());
    }

    public function testPutSavesWithJitteredTtl(): void
    {
        $pool = $this->createMock(StashPoolInterface::class);
        $item = $this->createMock(ItemInterface::class);
        $key = $this->newKey('put');

        $pool->expects($this->once())->method('getItem')->with('put')->willReturn($item);
        $item->expects($this->once())->method('set')->with('v');
        $item->expects($this->once())->method('expiresAfter')->with(555);
        $pool->expects($this->once())->method('save')->with($item);

        $loader = $this->createMock(LoaderInterface::class);
        $jitter = $this->createMock(JitterInterface::class);
        $jitter->method('apply')->with(600, $key)->willReturn(555);

        $cache = new Cache($pool, $loader, 600, 60, $jitter);
        $cache->put($key, 'v');
        $this->addToAssertionCount(1); // if no exceptions, it's fine
    }

    public function testInvalidateAsyncAndSync(): void
    {
        $pool = $this->createMock(StashPoolInterface::class);
        $driver = $this->createMock(StashDriverInterface::class);
        $pool->method('getDriver')->willReturn($driver);

        $loader = $this->createMock(LoaderInterface::class);
        $jitter = $this->createMock(JitterInterface::class);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $metrics = $this->createMock(MetricsInterface::class);

        $cache = new Cache($pool, $loader, 600, 60, $jitter, $dispatcher, $metrics);

        $selector = $this->newKey('sel');

        // ASYNC invalidate should dispatch and not touch driver
        $dispatcher->expects($this->once())->method('dispatch')->with($this->anything());
        $driver->expects($this->never())->method('clear');
        $cache->invalidate($selector, SyncMode::ASYNC);

        // SYNC invalidate should call driver->clear
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $cache = new Cache($pool, $loader, 600, 60, $jitter, $dispatcher, $metrics);
        $driver->expects($this->once())->method('clear')->with($selector);
        $cache->invalidate($selector, SyncMode::SYNC);

        // invalidateExact SYNC should call clear with second param true
        $driver->expects($this->once())->method('clear')->with($selector, true);
        $cache->invalidateExact($selector, SyncMode::SYNC);
    }

    public function testRefreshSyncLoadsAndPuts(): void
    {
        $pool = $this->createMock(StashPoolInterface::class);
        $item = $this->createMock(ItemInterface::class);
        $pool->method('getItem')->willReturn($item);

        $loader = $this->createMock(LoaderInterface::class);
        $jitter = $this->createMock(JitterInterface::class);

        $key = $this->newKey('r');

        $loader->expects($this->once())->method('resolve')->with($key)->willReturn('rv');
        $item->expects($this->once())->method('set')->with('rv');
        $item->expects($this->once())->method('expiresAfter')->with(600);
        $pool->expects($this->once())->method('save')->with($item);

        $cache = new Cache($pool, $loader, 600, 60, $jitter);
        $cache->refresh($key, SyncMode::SYNC);
    }
}
