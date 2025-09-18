<?php

declare(strict_types=1);

namespace Vendor\PackageName\Tests;

use PHPUnit\Framework\TestCase;
use Vendor\PackageName\ExampleClass;

class ExampleClassTest extends TestCase
{
    public function testCanBeInstantiated(): void
    {
        $instance = new ExampleClass();
        $this->assertInstanceOf(ExampleClass::class, $instance);
    }

    public function testHasDefaultMessage(): void
    {
        $instance = new ExampleClass();
        $this->assertEquals('Hello World!', $instance->getMessage());
    }

    public function testCanSetMessage(): void
    {
        $instance = new ExampleClass();
        $instance->setMessage('Custom message');
        $this->assertEquals('Custom message', $instance->getMessage());
    }

    public function testCanInstantiateWithCustomMessage(): void
    {
        $instance = new ExampleClass('Initial message');
        $this->assertEquals('Initial message', $instance->getMessage());
    }
}