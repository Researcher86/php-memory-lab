<?php

declare(strict_types=1);

namespace App\Tests\Native;

use App\Native\MappedFile;
use App\Native\NativeMemoryException;
use PHPUnit\Framework\TestCase;

final class MappedFileTest extends TestCase
{
    /** @var list<MappedFile> */
    private array $mappings = [];

    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->mappings as $mapping) {
            $mapping->unmap();
        }

        foreach ($this->paths as $path) {
            @unlink($path);
        }

        $this->mappings = [];
        $this->paths = [];
    }

    public function testWhatIsWrittenCanBeReadBack(): void
    {
        $mapping = $this->map(4096);

        $mapping->write(100, 'hello mapping');

        self::assertSame('hello mapping', $mapping->read(100, 13));
    }

    public function testAFreshMappingReadsAsZeroes(): void
    {
        self::assertSame(str_repeat("\0", 16), $this->map(4096)->read(0, 16));
    }

    public function testTheMappingIsPageAligned(): void
    {
        self::assertSame(0, $this->map(4096)->address() % 4096, 'mmap() always returns a page boundary');
    }

    public function testTheFileIsGrownToTheMappingSize(): void
    {
        $mapping = $this->map(8192);

        self::assertSame(8192, filesize($mapping->path));
    }

    /**
     * MAP_SHARED writes go through the page cache, so the file has the data
     * before anyone calls msync() - flushing is about surviving a power cut,
     * not about being visible.
     */
    public function testASharedWriteReachesTheFile(): void
    {
        $mapping = $this->map(4096, shared: true);

        $mapping->write(0, 'written through the mapping');
        $mapping->flush();

        self::assertStringStartsWith('written through the mapping', (string) file_get_contents($mapping->path));
    }

    /**
     * MAP_PRIVATE is Copy-on-Write against a file: the first write faults a
     * private copy of the page and the file never learns about it.
     */
    public function testAPrivateWriteNeverReachesTheFile(): void
    {
        $path = $this->path();
        file_put_contents($path, str_repeat('original', 512));

        $mapping = MappedFile::open($path, 4096, shared: false);
        $this->mappings[] = $mapping;

        $mapping->write(0, 'overwritten');

        self::assertSame('overwritten', $mapping->read(0, 11), 'the writer sees its own copy');
        self::assertStringStartsWith('original', (string) file_get_contents($path), 'the file does not');
    }

    public function testAReadPastTheEndIsRefusedRatherThanAttempted(): void
    {
        $mapping = $this->map(4096);

        $this->expectException(NativeMemoryException::class);
        $this->expectExceptionMessage('runs past the 4096-byte mapping');

        $mapping->read(4090, 10);
    }

    public function testAWritePastTheEndIsRefusedRatherThanAttempted(): void
    {
        $mapping = $this->map(4096);

        $this->expectException(NativeMemoryException::class);

        $mapping->write(4095, 'two bytes');
    }

    public function testNegativeOffsetsAreRefused(): void
    {
        $mapping = $this->map(4096);

        $this->expectException(NativeMemoryException::class);
        $this->expectExceptionMessage('Negative offset');

        $mapping->read(-1, 4);
    }

    public function testTheLastByteIsReachable(): void
    {
        $mapping = $this->map(4096);

        $mapping->write(4095, 'x');

        self::assertSame('x', $mapping->read(4095, 1));
    }

    public function testUnmappingTwiceIsSafe(): void
    {
        $mapping = $this->map(4096);

        $mapping->unmap();
        $mapping->unmap();

        self::assertFalse($mapping->isMapped());
    }

    public function testUsingAnUnmappedFileThrowsInsteadOfTouchingTheAddress(): void
    {
        $mapping = $this->map(4096);
        $mapping->unmap();

        $this->expectException(NativeMemoryException::class);
        $this->expectExceptionMessage('already unmapped');

        $mapping->read(0, 1);
    }

    public function testAZeroLengthMappingIsRefused(): void
    {
        $this->expectException(NativeMemoryException::class);

        MappedFile::open($this->path(), 0);
    }

    /**
     * The reason to map a file rather than read it: two processes over one
     * copy of the data, with the page cache as the meeting point.
     */
    public function testTwoProcessesShareOneMappingOfTheSameFile(): void
    {
        $mapping = $this->map(4096, shared: true);
        $mapping->write(0, 'from the parent');

        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);

        if ($pid === 0) {
            $child = MappedFile::open($mapping->path, 4096, shared: true);
            $child->write(64, strtoupper($child->read(0, 15)));
            $child->unmap();

            exit(0);
        }

        pcntl_waitpid($pid, $status);

        self::assertSame(0, pcntl_wexitstatus($status));
        self::assertSame('FROM THE PARENT', $mapping->read(64, 15), 'no flush needed: same physical pages');
    }

    private function map(int $size, bool $shared = true): MappedFile
    {
        $mapping = MappedFile::open($this->path(), $size, $shared);
        $this->mappings[] = $mapping;

        return $mapping;
    }

    private function path(): string
    {
        $path = sys_get_temp_dir() . '/mapped-file-test-' . bin2hex(random_bytes(8)) . '.bin';
        $this->paths[] = $path;

        return $path;
    }
}
