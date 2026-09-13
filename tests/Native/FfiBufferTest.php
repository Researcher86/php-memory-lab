<?php

declare(strict_types=1);

namespace App\Tests\Native;

use App\Native\FfiBuffer;
use App\Native\NativeMemoryException;
use PHPUnit\Framework\TestCase;

final class FfiBufferTest extends TestCase
{
    public function testWhatIsWrittenCanBeReadBack(): void
    {
        $buffer = FfiBuffer::allocate(1024);

        $buffer->write(16, 'native bytes');

        self::assertSame('native bytes', $buffer->read(16, 12));
    }

    public function testTheWholeBufferIsAddressable(): void
    {
        $buffer = FfiBuffer::allocate(256);

        $buffer->write(0, str_repeat('a', 256));

        self::assertSame(str_repeat('a', 256), $buffer->read(0, 256));
    }

    public function testTheLastByteIsReachable(): void
    {
        $buffer = FfiBuffer::allocate(64);

        $buffer->write(63, 'z');

        self::assertSame('z', $buffer->read(63, 1));
    }

    public function testReadingBeyondTheEndIsRefused(): void
    {
        $buffer = FfiBuffer::allocate(64);

        $this->expectException(NativeMemoryException::class);
        $this->expectExceptionMessage('runs past the 64-byte buffer');

        $buffer->read(60, 8);
    }

    public function testWritingBeyondTheEndIsRefused(): void
    {
        $buffer = FfiBuffer::allocate(64);

        $this->expectException(NativeMemoryException::class);

        $buffer->write(60, 'more than four bytes');
    }

    public function testAnOffsetAtTheEndIsRefusedForAnyLength(): void
    {
        $buffer = FfiBuffer::allocate(64);

        $this->expectException(NativeMemoryException::class);

        $buffer->read(64, 1);
    }

    public function testNegativeOffsetsAreRefused(): void
    {
        $buffer = FfiBuffer::allocate(64);

        $this->expectException(NativeMemoryException::class);
        $this->expectExceptionMessage('Negative offset');

        $buffer->read(-1, 4);
    }

    public function testNegativeLengthsAreRefused(): void
    {
        $buffer = FfiBuffer::allocate(64);

        $this->expectException(NativeMemoryException::class);

        $buffer->read(0, -8);
    }

    /**
     * The check is deliberately not `$offset + $length > $size`: that sum
     * overflows for a large offset and wraps negative, which would turn the
     * guard into a guarantee of the access it exists to stop.
     */
    public function testAnOffsetThatWouldOverflowTheBoundsCheckIsRefused(): void
    {
        $buffer = FfiBuffer::allocate(64);

        $this->expectException(NativeMemoryException::class);

        $buffer->read(PHP_INT_MAX, PHP_INT_MAX);
    }

    public function testAZeroSizedAllocationIsRefused(): void
    {
        $this->expectException(NativeMemoryException::class);

        FfiBuffer::allocate(0);
    }

    public function testANegativeAllocationIsRefused(): void
    {
        $this->expectException(NativeMemoryException::class);

        FfiBuffer::allocate(-1);
    }

    public function testFreeingTwiceIsSafeEvenThoughFreeIsNot(): void
    {
        $buffer = FfiBuffer::allocate(64);

        $buffer->free();
        $buffer->free();

        self::assertFalse($buffer->isAllocated());
    }

    public function testUsingAFreedBufferThrowsInsteadOfTouchingTheAddress(): void
    {
        $buffer = FfiBuffer::allocate(64);
        $buffer->free();

        $this->expectException(NativeMemoryException::class);
        $this->expectExceptionMessage('already freed');

        $buffer->read(0, 1);
    }

    public function testAllocationsDoNotOverlap(): void
    {
        $first = FfiBuffer::allocate(1024);
        $second = FfiBuffer::allocate(1024);

        $first->write(0, str_repeat('1', 1024));
        $second->write(0, str_repeat('2', 1024));

        self::assertNotSame($first->address(), $second->address());
        self::assertSame(str_repeat('1', 1024), $first->read(0, 1024));
    }

    /**
     * The bytes are libc's, not the engine's. A 32 MiB buffer moves
     * memory_get_usage() by nothing at all.
     */
    public function testNativeMemoryIsInvisibleToThePhpCounters(): void
    {
        $before = memory_get_usage();
        $buffer = FfiBuffer::allocate(32 * 1024 * 1024);
        $buffer->write(0, 'touched');
        $after = memory_get_usage();
        $buffer->free();

        self::assertLessThan(1024, $after - $before);
    }
}
