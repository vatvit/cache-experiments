<?php

namespace Cache\Tests;

use Cache\ValueResult;
use PHPUnit\Framework\TestCase;

class ValueResultTest extends TestCase
{
    public function testHitFactoryMethod(): void
    {
        $value = 'test_value';
        $createdAt = 1234567890;
        $softExpiresAt = 1234567950;

        $result = ValueResult::hit($value, $createdAt, $softExpiresAt);

        $this->assertTrue($result->isHit());
        $this->assertFalse($result->isStale());
        $this->assertFalse($result->isMiss());
        $this->assertEquals($value, $result->value());
        $this->assertEquals($createdAt, $result->createdAt());
        $this->assertEquals($softExpiresAt, $result->softExpiresAt());
    }

    public function testStaleFactoryMethod(): void
    {
        $value = ['key' => 'value'];
        $createdAt = 1234567800;
        $softExpiresAt = 1234567850;

        $result = ValueResult::stale($value, $createdAt, $softExpiresAt);

        $this->assertFalse($result->isHit());
        $this->assertTrue($result->isStale());
        $this->assertFalse($result->isMiss());
        $this->assertEquals($value, $result->value());
        $this->assertEquals($createdAt, $result->createdAt());
        $this->assertEquals($softExpiresAt, $result->softExpiresAt());
    }

    public function testMissFactoryMethod(): void
    {
        $result = ValueResult::miss();

        $this->assertFalse($result->isHit());
        $this->assertFalse($result->isStale());
        $this->assertTrue($result->isMiss());
        $this->assertNull($result->createdAt());
        $this->assertNull($result->softExpiresAt());
    }

    public function testMissValueThrowsException(): void
    {
        $result = ValueResult::miss();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ValueResult: no value (miss).');

        $result->value();
    }

    public function testHitWithNullValue(): void
    {
        $result = ValueResult::hit(null, 1234567890, 1234567950);

        $this->assertTrue($result->isHit());
        $this->assertNull($result->value());
    }

    public function testStaleWithNullValue(): void
    {
        $result = ValueResult::stale(null, 1234567890, 1234567950);

        $this->assertTrue($result->isStale());
        $this->assertNull($result->value());
    }

    public function testHitWithComplexValue(): void
    {
        $complexValue = [
            'string' => 'test',
            'number' => 42,
            'array' => [1, 2, 3],
            'object' => (object) ['prop' => 'value']
        ];

        $result = ValueResult::hit($complexValue, 1234567890, 1234567950);

        $this->assertTrue($result->isHit());
        $this->assertEquals($complexValue, $result->value());
    }

    public function testStaleWithComplexValue(): void
    {
        $complexValue = [
            'nested' => [
                'deeply' => [
                    'value' => 'test'
                ]
            ]
        ];

        $result = ValueResult::stale($complexValue, 1234567890, 1234567950);

        $this->assertTrue($result->isStale());
        $this->assertEquals($complexValue, $result->value());
    }

    public function testHitWithZeroTimestamps(): void
    {
        $result = ValueResult::hit('value', 0, 0);

        $this->assertTrue($result->isHit());
        $this->assertEquals('value', $result->value());
        $this->assertEquals(0, $result->createdAt());
        $this->assertEquals(0, $result->softExpiresAt());
    }

    public function testStaleWithZeroTimestamps(): void
    {
        $result = ValueResult::stale('value', 0, 0);

        $this->assertTrue($result->isStale());
        $this->assertEquals('value', $result->value());
        $this->assertEquals(0, $result->createdAt());
        $this->assertEquals(0, $result->softExpiresAt());
    }

    public function testHitWithNegativeTimestamps(): void
    {
        $result = ValueResult::hit('value', -100, -50);

        $this->assertTrue($result->isHit());
        $this->assertEquals('value', $result->value());
        $this->assertEquals(-100, $result->createdAt());
        $this->assertEquals(-50, $result->softExpiresAt());
    }

    public function testStaleWithNegativeTimestamps(): void
    {
        $result = ValueResult::stale('value', -200, -100);

        $this->assertTrue($result->isStale());
        $this->assertEquals('value', $result->value());
        $this->assertEquals(-200, $result->createdAt());
        $this->assertEquals(-100, $result->softExpiresAt());
    }

    public function testStateConsistency(): void
    {
        $hitResult = ValueResult::hit('value', 1234567890, 1234567950);
        $staleResult = ValueResult::stale('value', 1234567890, 1234567950);
        $missResult = ValueResult::miss();

        // Test that only one state is true for each result
        $this->assertTrue($hitResult->isHit() && !$hitResult->isStale() && !$hitResult->isMiss());
        $this->assertTrue(!$staleResult->isHit() && $staleResult->isStale() && !$staleResult->isMiss());
        $this->assertTrue(!$missResult->isHit() && !$missResult->isStale() && $missResult->isMiss());
    }

    public function testHitWithStringValue(): void
    {
        $result = ValueResult::hit('simple string', 1000, 2000);

        $this->assertTrue($result->isHit());
        $this->assertEquals('simple string', $result->value());
    }

    public function testHitWithIntegerValue(): void
    {
        $result = ValueResult::hit(12345, 1000, 2000);

        $this->assertTrue($result->isHit());
        $this->assertEquals(12345, $result->value());
    }

    public function testHitWithBooleanValue(): void
    {
        $trueResult = ValueResult::hit(true, 1000, 2000);
        $falseResult = ValueResult::hit(false, 1000, 2000);

        $this->assertTrue($trueResult->isHit());
        $this->assertTrue($trueResult->value());

        $this->assertTrue($falseResult->isHit());
        $this->assertFalse($falseResult->value());
    }

    public function testStaleWithBooleanValue(): void
    {
        $trueResult = ValueResult::stale(true, 1000, 2000);
        $falseResult = ValueResult::stale(false, 1000, 2000);

        $this->assertTrue($trueResult->isStale());
        $this->assertTrue($trueResult->value());

        $this->assertTrue($falseResult->isStale());
        $this->assertFalse($falseResult->value());
    }
}
