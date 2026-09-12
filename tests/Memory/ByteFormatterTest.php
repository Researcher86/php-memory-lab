<?php

declare(strict_types=1);

namespace App\Tests\Memory;

use App\Memory\ByteFormatter;
use PHPUnit\Framework\TestCase;

final class ByteFormatterTest extends TestCase
{
    public function testZero(): void
    {
        self::assertSame('0 B', ByteFormatter::format(0));
    }

    public function testBytesBelowOneKib(): void
    {
        self::assertSame('1023 B', ByteFormatter::format(1023));
    }

    public function testKibBoundary(): void
    {
        self::assertSame('1.00 KiB', ByteFormatter::format(1024));
    }

    public function testMibBoundary(): void
    {
        self::assertSame('1.00 MiB', ByteFormatter::format(1 << 20));
    }

    public function testGibBoundary(): void
    {
        self::assertSame('1.00 GiB', ByteFormatter::format(1 << 30));
    }

    public function testFractionalKib(): void
    {
        self::assertSame('1.50 KiB', ByteFormatter::format(1536));
    }

    public function testPrecisionZeroRoundsUp(): void
    {
        self::assertSame('2 KiB', ByteFormatter::format(1536, 0));
    }

    public function testNegative(): void
    {
        self::assertSame('-1.00 KiB', ByteFormatter::format(-1024));
    }

    public function testSignedPositive(): void
    {
        self::assertSame('+1.00 KiB', ByteFormatter::formatSigned(1024));
    }

    public function testSignedZero(): void
    {
        self::assertSame('0 B', ByteFormatter::formatSigned(0));
    }

    public function testSignedNegative(): void
    {
        self::assertSame('-1.00 KiB', ByteFormatter::formatSigned(-1024));
    }
}
