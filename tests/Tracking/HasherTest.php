<?php

declare(strict_types=1);

namespace Keepnew\Tests\Tracking;

use Keepnew\Tracking\Hasher;
use PHPUnit\Framework\TestCase;

final class HasherTest extends TestCase
{
    public function testEmailIsNormalisedThenHashed(): void
    {
        $expected = hash('sha256', 'marie@test.be');
        self::assertSame($expected, Hasher::email('  Marie@Test.BE '));
    }

    public function testBelgianPhoneNormalisedToE164(): void
    {
        self::assertSame('32470123456', Hasher::normalizePhone('0470 12 34 56'));
        self::assertSame('32470123456', Hasher::normalizePhone('+32 470 12 34 56'));
        self::assertSame('32470123456', Hasher::normalizePhone('0032470123456'));
        self::assertSame(hash('sha256', '32470123456'), Hasher::phone('0470/12.34.56'));
    }

    public function testEmptyPhoneStaysEmpty(): void
    {
        self::assertSame('', Hasher::normalizePhone('   '));
    }
}
