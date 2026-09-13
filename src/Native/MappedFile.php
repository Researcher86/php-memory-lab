<?php

declare(strict_types=1);

namespace App\Native;

use FFI;
use FFI\CData;

/**
 * A file mapped into this process's address space.
 *
 * `mmap()` does not read the file. It adds a region to the page table that
 * says "these addresses come from that file", and nothing is fetched until
 * something touches a page - at which point the fault handler reads exactly
 * that page. A mapping of a gigabyte costs a page-table entry and no RSS
 * until it is used, which is the difference this whole phase is about: a
 * `file_get_contents()` of the same file costs a gigabyte immediately.
 *
 * Two mapping modes, and the difference is the point:
 *
 *   MAP_SHARED  - writes go to the page cache, so the file changes and every
 *                 other process mapping the same file sees it. This is shared
 *                 memory that happens to have a name in the filesystem.
 *   MAP_PRIVATE - writes fault a private copy of the page, exactly as after a
 *                 fork(). The file never changes and nobody else sees a
 *                 thing; Copy-on-Write, applied to a file.
 *
 * The mapping length is rounded up to a page by the kernel, but the *file*
 * is not: reading past the end of the file within the last page gives zeros,
 * and touching a page entirely past the end of the file raises SIGBUS, which
 * PHP cannot catch. Hence the bounds checks on every access - the alternative
 * is not an exception, it is the process disappearing.
 */
final class MappedFile
{
    private ?CData $address = null;

    private function __construct(
        public readonly string $path,
        public readonly int $size,
        public readonly bool $shared,
        CData $address,
        private readonly int $descriptor,
    ) {
        $this->address = $address;
    }

    /**
     * Creates the file if needed, grows it to $size, and maps all of it.
     *
     * @param bool $shared MAP_SHARED (writes reach the file) or MAP_PRIVATE
     *                     (writes stay in this process)
     */
    public static function open(string $path, int $size, bool $shared = true): self
    {
        if ($size < 1) {
            throw new NativeMemoryException('A mapping needs at least one byte');
        }

        $descriptor = Libc::open($path, Libc::O_RDWR | Libc::O_CREAT, 0o644);

        if ($descriptor < 0) {
            throw new NativeMemoryException(\sprintf('Unable to open %s: %s', $path, Libc::lastError()));
        }

        // The file must be at least as long as the mapping. Mapping past the
        // end of a file is allowed and then kills the process with SIGBUS on
        // first touch, which is a worse way to find out.
        if (Libc::ftruncate($descriptor, $size) !== 0) {
            $error = Libc::lastError();
            Libc::close($descriptor);

            throw new NativeMemoryException(\sprintf('Unable to size %s to %d bytes: %s', $path, $size, $error));
        }

        $address = Libc::mmap(
            $size,
            Libc::PROT_READ | Libc::PROT_WRITE,
            $shared ? Libc::MAP_SHARED : Libc::MAP_PRIVATE,
            $descriptor,
            0,
        );

        if (Libc::addressOf($address) === Libc::MAP_FAILED) {
            $error = Libc::lastError();
            Libc::close($descriptor);

            throw new NativeMemoryException(\sprintf('Unable to map %s: %s', $path, $error));
        }

        return new self($path, $size, $shared, $address, $descriptor);
    }

    public function read(int $offset, int $length): string
    {
        $this->assertWithinBounds($offset, $length);

        if ($length <= 0) {
            return '';
        }

        $bytes = Libc::cast('char *', $this->requireAddress());

        return FFI::string(FFI::addr($bytes[$offset]), $length);
    }

    public function write(int $offset, string $data): void
    {
        $length = \strlen($data);
        $this->assertWithinBounds($offset, $length);

        if ($length === 0) {
            return;
        }

        $bytes = Libc::cast('char *', $this->requireAddress());
        FFI::memcpy(FFI::addr($bytes[$offset]), $data, $length);
    }

    /**
     * Pushes dirty pages to the file and waits for them.
     *
     * Without it the data is still in the page cache and still visible to
     * every other process on the machine - the kernel writes it back when it
     * feels like it. flush() matters for durability across a power cut, not
     * for visibility to other processes, which is immediate.
     */
    public function flush(): void
    {
        if (Libc::msync($this->requireAddress(), $this->size, Libc::MS_SYNC) !== 0) {
            throw new NativeMemoryException(\sprintf('Unable to flush %s: %s', $this->path, Libc::lastError()));
        }
    }

    /**
     * Unmaps the region and closes the descriptor. Idempotent - a second call
     * does nothing, where a second munmap() of the same address is undefined
     * behaviour.
     */
    public function unmap(): void
    {
        if ($this->address === null) {
            return;
        }

        Libc::munmap($this->address, $this->size);
        Libc::close($this->descriptor);
        $this->address = null;
    }

    public function isMapped(): bool
    {
        return $this->address !== null;
    }

    /** Where the kernel put the mapping. Always page-aligned. */
    public function address(): int
    {
        return Libc::addressOf($this->requireAddress());
    }

    /**
     * The boundary this class exists to hold. A read past the end of a
     * mapping is not an error C reports - it is either somebody else's memory
     * or a SIGBUS, and neither can be caught from PHP.
     */
    private function assertWithinBounds(int $offset, int $length): void
    {
        if ($offset < 0 || $length < 0) {
            throw new NativeMemoryException(\sprintf('Negative offset (%d) or length (%d)', $offset, $length));
        }

        if ($offset + $length > $this->size) {
            throw new NativeMemoryException(\sprintf(
                'Access of %d bytes at offset %d runs past the %d-byte mapping of %s',
                $length,
                $offset,
                $this->size,
                $this->path,
            ));
        }
    }

    private function requireAddress(): CData
    {
        if ($this->address === null) {
            throw new NativeMemoryException(\sprintf('The mapping of %s was already unmapped', $this->path));
        }

        return $this->address;
    }
}
