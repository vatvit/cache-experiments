<?php

namespace Cache\Tests;

use Cache\Key;
use PHPUnit\Framework\TestCase;

class KeyTest extends TestCase
{
    public function testBasicConstructorWithRequiredParameters(): void
    {
        $key = new Key('mydomain', 'myfacet', 'myid');

        $this->assertSame('mydomain', $key->domain());
        $this->assertSame('myfacet', $key->facet());
        $this->assertNull($key->schemaVersion());
        $this->assertNull($key->locale());
        $this->assertSame('myid', $key->id());
        $this->assertSame('myid', $key->idString());
    }

    public function testConstructorWithAllParameters(): void
    {
        $key = new Key('mydomain', 'myfacet', 'myid', 'v1.0', 'en-US');

        $this->assertSame('mydomain', $key->domain());
        $this->assertSame('myfacet', $key->facet());
        $this->assertSame('v1.0', $key->schemaVersion());
        $this->assertSame('en-US', $key->locale());
        $this->assertSame('myid', $key->id());
        $this->assertSame('myid', $key->idString());
    }

    public function testConstructorWithIntegerId(): void
    {
        $key = new Key('mydomain', 'myfacet', 123);

        $this->assertSame('123', $key->id());
        $this->assertSame('123', $key->idString());
    }

    public function testConstructorWithArrayId(): void
    {
        $arrayId = ['user' => 123, 'type' => 'profile'];
        $key = new Key('mydomain', 'myfacet', $arrayId);

        // Key normalizes arrays by sorting keys
        $expectedNormalized = ['type' => 'profile', 'user' => 123];
        $this->assertSame($expectedNormalized, $key->id());
        $this->assertIsString($key->idString());
        $this->assertStringStartsWith('j:', $key->idString());
    }

    public function testConstructorWithNestedArrayId(): void
    {
        $nestedArrayId = [
            'user' => ['id' => 123, 'name' => 'john'],
            'filters' => ['status' => 'active', 'role' => 'admin']
        ];
        $key = new Key('mydomain', 'myfacet', $nestedArrayId);

        // Key normalizes arrays by sorting keys recursively
        $expectedNormalized = [
            'filters' => ['role' => 'admin', 'status' => 'active'],
            'user' => ['id' => 123, 'name' => 'john']
        ];
        $this->assertSame($expectedNormalized, $key->id());
        $this->assertIsString($key->idString());
        $this->assertStringStartsWith('j:', $key->idString());
    }

    public function testArrayIdNormalization(): void
    {
        // Test that array order doesn't matter for ID generation
        $id1 = ['b' => 2, 'a' => 1];
        $id2 = ['a' => 1, 'b' => 2];

        $key1 = new Key('mydomain', 'myfacet', $id1);
        $key2 = new Key('mydomain', 'myfacet', $id2);

        $this->assertSame($key1->idString(), $key2->idString());
    }

    public function testToStringMethod(): void
    {
        $key = new Key('mydomain', 'myfacet', 'myid');

        $expected = 'mydomain/myfacet/myid';
        $this->assertSame($expected, $key->toString());
        $this->assertSame($expected, (string)$key);
    }

    public function testToStringWithAllParameters(): void
    {
        $key = new Key('mydomain', 'myfacet', 'myid', 'v1.0', 'en-US');

        $expected = 'mydomain/myfacet/v1.0/en-US/myid';
        $this->assertSame($expected, $key->toString());
    }

    public function testPrefixString(): void
    {
        $key = new Key('mydomain', 'myfacet', 'myid');
        $this->assertSame('mydomain/myfacet', $key->prefixString());

        $keyWithVersion = new Key('mydomain', 'myfacet', 'myid', 'v1.0');
        $this->assertSame('mydomain/myfacet/v1.0', $keyWithVersion->prefixString());

        $keyWithAll = new Key('mydomain', 'myfacet', 'myid', 'v1.0', 'en-US');
        $this->assertSame('mydomain/myfacet/v1.0/en-US', $keyWithAll->prefixString());
    }

    public function testSegments(): void
    {
        $key = new Key('mydomain', 'myfacet', 'myid');
        $expected = ['mydomain', 'myfacet', 'myid'];
        $this->assertSame($expected, $key->segments());

        $keyWithAll = new Key('mydomain', 'myfacet', 'myid', 'v1.0', 'en-US');
        $expectedWithAll = ['mydomain', 'myfacet', 'v1.0', 'en-US', 'myid'];
        $this->assertSame($expectedWithAll, $keyWithAll->segments());
    }

    public function testPrefixSegments(): void
    {
        $key = new Key('mydomain', 'myfacet', 'myid');
        $expected = ['mydomain', 'myfacet'];
        $this->assertSame($expected, $key->prefixSegments());

        $keyWithAll = new Key('mydomain', 'myfacet', 'myid', 'v1.0', 'en-US');
        $expectedWithAll = ['mydomain', 'myfacet', 'v1.0', 'en-US'];
        $this->assertSame($expectedWithAll, $keyWithAll->prefixSegments());
    }

    public function testUrlEncoding(): void
    {
        $key = new Key('my domain', 'my/facet', 'my id');

        $this->assertSame('my%20domain/my%2Ffacet/my%20id', $key->toString());
        $this->assertSame('my%20domain/my%2Ffacet', $key->prefixString());
    }

    public function testEmptyStringHandling(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Key segment must be non-empty.');

        new Key('', 'myfacet', 'myid');
    }

    public function testEmptyFacetHandling(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Key segment must be non-empty.');

        new Key('mydomain', '', 'myid');
    }

    public function testWhitespaceOnlyDomain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Key segment must be non-empty.');

        new Key('   ', 'myfacet', 'myid');
    }

    public function testWhitespaceOnlyFacet(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Key segment must be non-empty.');

        new Key('mydomain', '   ', 'myid');
    }

    public function testTrimming(): void
    {
        $key = new Key('  mydomain  ', '  myfacet  ', 'myid', '  v1.0  ', '  en-US  ');

        $this->assertSame('mydomain', $key->domain());
        $this->assertSame('myfacet', $key->facet());
        $this->assertSame('v1.0', $key->schemaVersion());
        $this->assertSame('en-US', $key->locale());
    }

    public function testEmptySchemaVersionBecomesNull(): void
    {
        $key = new Key('mydomain', 'myfacet', 'myid', '');
        $this->assertNull($key->schemaVersion());
    }

    public function testWhitespaceSchemaVersionThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Key segment must be non-empty.');

        new Key('mydomain', 'myfacet', 'myid', '   ');
    }

    public function testEmptyLocaleBecomesNull(): void
    {
        $key = new Key('mydomain', 'myfacet', 'myid', null, '');
        $this->assertNull($key->locale());
    }

    public function testWhitespaceLocaleThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Key segment must be non-empty.');

        new Key('mydomain', 'myfacet', 'myid', null, '   ');
    }

    public function testSpecialCharactersInId(): void
    {
        $specialId = 'id with spaces/slashes&ampersands=equals+plus';
        $key = new Key('mydomain', 'myfacet', $specialId);

        $this->assertSame($specialId, $key->id());
        $this->assertSame($specialId, $key->idString());
        $this->assertStringContainsString(rawurlencode($specialId), $key->toString());
    }

    public function testComplexArrayIdStringification(): void
    {
        $complexId = [
            'nested' => ['deep' => ['value' => 123]],
            'unicode' => 'café',
            'special' => 'chars/with&symbols=here'
        ];

        $key = new Key('mydomain', 'myfacet', $complexId);
        $idString = $key->idString();

        $this->assertStringStartsWith('j:', $idString);
        $this->assertIsString($idString);

        // Test that the same complex array produces the same string
        $key2 = new Key('mydomain', 'myfacet', $complexId);
        $this->assertSame($idString, $key2->idString());
    }

    public function testBase64UrlEncoding(): void
    {
        // Create an array ID that will produce base64 with +, /, and = characters
        $arrayId = ['test' => str_repeat('a', 100)];
        $key = new Key('mydomain', 'myfacet', $arrayId);
        $idString = $key->idString();

        // Remove the 'j:' prefix
        $base64Part = substr($idString, 2);

        // Ensure no standard base64 characters that should be URL-encoded
        $this->assertStringNotContainsString('+', $base64Part);
        $this->assertStringNotContainsString('/', $base64Part);
        $this->assertStringNotContainsString('=', $base64Part);
    }

    public function testConsistentIdStringGeneration(): void
    {
        // Test that the same input always produces the same output
        $id = ['user' => 123, 'type' => 'test', 'data' => ['nested' => true]];

        $key1 = new Key('domain', 'facet', $id);
        $key2 = new Key('domain', 'facet', $id);
        $key3 = new Key('domain', 'facet', $id);

        $this->assertSame($key1->idString(), $key2->idString());
        $this->assertSame($key2->idString(), $key3->idString());
        $this->assertSame($key1->toString(), $key2->toString());
    }
}
