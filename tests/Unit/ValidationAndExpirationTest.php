<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Tests\Unit;

use DateInterval;
use DateTimeImmutable;
use Omegaalfa\Cache\Exception\InvalidArgumentException;
use Omegaalfa\Cache\Exception\InvalidKeyException;
use Omegaalfa\Cache\Internal\Expiration;
use Omegaalfa\Cache\Internal\KeyValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidationAndExpirationTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'braces' => ['a{b'];
        yield 'parenthesis' => ['a(b'];
        yield 'slash' => ['a/b'];
        yield 'backslash' => ['a\\b'];
        yield 'at' => ['a@b'];
        yield 'colon' => ['a:b'];
    }

    #[DataProvider('invalidKeys')]
    public function testReservedKeysAreRejected(string $key): void
    {
        $this->expectException(InvalidKeyException::class);

        KeyValidator::validate($key);
    }

    public function testUnicodeSpacesAndLongKeysAreAccepted(): void
    {
        KeyValidator::validate('usu?rio 123');
        KeyValidator::validate(str_repeat('a', 1024));

        self::assertSame('usu?rio 123', 'usu?rio 123');
    }

    public function testExpirationSupportsNullIntegerIntervalAndPastValues(): void
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00Z');

        self::assertNull(Expiration::after(null, $now));
        self::assertSame($now->getTimestamp() + 10, Expiration::after(10, $now));
        self::assertSame($now->getTimestamp() + 10, Expiration::after(new DateInterval('PT10S'), $now));
        self::assertSame($now->getTimestamp(), Expiration::after(0, $now));
        self::assertSame($now->getTimestamp(), Expiration::after(-10, $now));
    }

    public function testOverflowingTtlIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Expiration::after(PHP_INT_MAX, new DateTimeImmutable('2026-01-01T00:00:00Z'));
    }
}
