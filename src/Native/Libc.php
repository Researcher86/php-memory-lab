<?php

declare(strict_types=1);

namespace App\Native;

use FFI;
use FFI\CData;

/**
 * The handful of libc calls this lab reaches for directly, behind one lazily
 * loaded FFI handle.
 *
 * PHP has no `mmap()`, no `malloc()` and no way to get the file descriptor
 * behind a stream, so anything below the engine's own allocator has to be
 * called through FFI. One shared handle rather than a `FFI::cdef()` per class,
 * because each cdef parses the declarations again and opens libc again - and
 * because the declarations are the interesting part: they are this lab's only
 * contract with the C ABI, and a wrong one is undefined behaviour rather than
 * an error.
 *
 * The wrappers are thin on purpose, and they are here so that the rest of the
 * lab can be typed. FFI's calls are dynamic - `$handle->mmap(...)` is resolved
 * at runtime and invisible to static analysis - so every such call is made in
 * this file and nowhere else, and callers get ordinary typed methods.
 *
 * Everything here is Linux on a 64-bit platform. The constants are values the
 * kernel headers define and PHP cannot discover, so they are spelled out with
 * the header they come from.
 */
final class Libc
{
    /** <fcntl.h> */
    public const O_RDWR = 2;
    public const O_CREAT = 64;

    /** <sys/mman.h> */
    public const PROT_READ = 1;
    public const PROT_WRITE = 2;
    public const MAP_SHARED = 1;
    public const MAP_PRIVATE = 2;
    public const MAP_ANONYMOUS = 0x20;
    public const MS_SYNC = 4;

    /** mmap() reports failure as the address -1, not as null. */
    public const MAP_FAILED = -1;

    /** <unistd.h>, the argument that asks sysconf() for the page size. */
    private const SC_PAGESIZE = 30;

    private static ?FFI $handle = null;

    private function __construct()
    {
    }

    /** @return int the file descriptor, or a negative number on failure */
    public static function open(string $path, int $flags, int $mode): int
    {
        return (int) self::handle()->open($path, $flags, $mode);
    }

    public static function close(int $descriptor): int
    {
        return (int) self::handle()->close($descriptor);
    }

    public static function ftruncate(int $descriptor, int $length): int
    {
        return (int) self::handle()->ftruncate($descriptor, $length);
    }

    /**
     * @return CData the mapped address; check it against MAP_FAILED with
     *               addressOf() before using it
     */
    public static function mmap(int $length, int $protection, int $flags, int $descriptor, int $offset = 0): CData
    {
        /** @var CData $address */
        $address = self::handle()->mmap(null, $length, $protection, $flags, $descriptor, $offset);

        return $address;
    }

    public static function munmap(CData $address, int $length): int
    {
        return (int) self::handle()->munmap($address, $length);
    }

    public static function msync(CData $address, int $length, int $flags): int
    {
        return (int) self::handle()->msync($address, $length, $flags);
    }

    /** @return CData|null the allocation, or null when the allocator refused */
    public static function malloc(int $size): ?CData
    {
        /** @var CData|null $pointer */
        $pointer = self::handle()->malloc($size);

        return $pointer;
    }

    public static function free(CData $pointer): void
    {
        self::handle()->free($pointer);
    }

    /** Reinterprets a pointer as another type without copying anything. */
    public static function cast(string $type, CData $pointer): CData
    {
        return self::handle()->cast($type, $pointer);
    }

    /**
     * The kernel's page size - the unit everything about mapping is measured
     * in, and a property of the running kernel rather than a constant, so it
     * is asked for rather than assumed.
     */
    public static function pageSize(): int
    {
        return (int) self::handle()->sysconf(self::SC_PAGESIZE);
    }

    /**
     * The numeric address a pointer holds.
     *
     * Casting a pointer to an integer type segfaults this PHP build, so the
     * pointer is stored in a one-element array and its eight bytes are read
     * back as an integer instead. Needed because a failed mmap() reports
     * itself as the address -1 rather than as null, and telling those apart
     * means looking at the number.
     */
    public static function addressOf(CData $pointer): int
    {
        $holder = self::handle()->new('void*[1]');
        $holder[0] = $pointer;

        /** @var array{1: int} $address */
        $address = unpack('P', FFI::string(FFI::addr($holder), 8));

        return $address[1];
    }

    /** The last error a libc call recorded, as a human-readable string. */
    public static function lastError(): string
    {
        $handle = self::handle();
        $errno = (int) $handle->__errno_location()[0];

        return sprintf('%s (errno %d)', FFI::string($handle->strerror($errno)), $errno);
    }

    private static function handle(): FFI
    {
        return self::$handle ??= FFI::cdef(
            <<<'C'
                int open(const char *pathname, int flags, int mode);
                int close(int fd);
                int ftruncate(int fd, long length);
                void *mmap(void *addr, size_t length, int prot, int flags, int fd, long offset);
                int munmap(void *addr, size_t length);
                int msync(void *addr, size_t length, int flags);
                void *malloc(size_t size);
                void free(void *ptr);
                long sysconf(int name);
                int *__errno_location(void);
                char *strerror(int errnum);
                C,
            'libc.so.6',
        );
    }
}
