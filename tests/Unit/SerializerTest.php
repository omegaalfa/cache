<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Tests\Unit;

use Omegaalfa\Cache\Exception\SerializationException;
use Omegaalfa\Cache\Serializer\JsonSerializer;
use Omegaalfa\Cache\Serializer\NativeSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class SerializerTest extends TestCase
{
    /** @return iterable<string, array{mixed}> */
    public static function nativeValues(): iterable
    {
        yield 'string' => ['value'];
        yield 'integer' => [42];
        yield 'float' => [1.5];
        yield 'false' => [false];
        yield 'null' => [null];
        yield 'array' => [['key' => 'value']];

        $object = new stdClass();
        $object->value = 42;
        yield 'object' => [$object];
    }

    #[DataProvider('nativeValues')]
    public function testNativeRoundTrip(mixed $value): void
    {
        $serializer = new NativeSerializer();

        self::assertEquals($value, $serializer->unserialize($serializer->serialize($value)));
    }

    public function testNativeRejectsMalformedPayload(): void
    {
        $this->expectException(SerializationException::class);

        (new NativeSerializer())->unserialize('not-a-serialized-value');
    }

    public function testNativeCanDisallowClasses(): void
    {
        $payload = serialize(new stdClass());
        $value = (new NativeSerializer(false))->unserialize($payload);

        self::assertInstanceOf(\__PHP_Incomplete_Class::class, $value);
    }

    public function testJsonRoundTripAndBigInteger(): void
    {
        $serializer = new JsonSerializer();

        self::assertSame(
            ['string' => 'ol?', 'float' => 1.0, 'null' => null],
            $serializer->unserialize($serializer->serialize([
                'string' => 'ol?',
                'float' => 1.0,
                'null' => null,
            ])),
        );
        self::assertSame('9223372036854775808', $serializer->unserialize('9223372036854775808'));
    }

    public function testJsonRejectsInvalidUtf8AndMalformedJson(): void
    {
        $serializer = new JsonSerializer();

        try {
            $serializer->serialize("\xB1\x31");
            self::fail('Invalid UTF-8 should fail.');
        } catch (SerializationException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(SerializationException::class);
        $serializer->unserialize('{');
    }
}
