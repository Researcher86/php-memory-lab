<?php

declare(strict_types=1);

namespace App\Native;

use FFI;
use FFI\CData;

/**
 * A block of memory from libc's allocator, outside everything PHP knows about.
 *
 * `malloc()` returns an address. Nothing else comes with it: no length, no
 * type, no refcount, no garbage collector, and no check on any access. The
 * buffer knows how big it is because this class remembers - the memory itself
 * does not, and neither does the pointer.
 *
 * That is the whole subject of the phase, and it has three consequences worth
 * stating before the code:
 *
 *   Invisible to PHP. `memory_get_usage()` never sees these bytes and
 *   `memory_limit` never restrains them. They show up in RSS once touched,
 *   which makes a process whose PHP memory is flat and whose RSS climbs a
 *   perfectly ordinary thing to be looking at.
 *
 *   Owned by nobody. PHP will not free it. Losing the last reference to this
 *   object without freeing would leak the allocation for the life of the
 *   process - hence the destructor, which is a safety net rather than the
 *   intended route.
 *
 *   Unpoliced. A read past the end returns whatever is next in the heap; a
 *   write past the end corrupts it, usually reported much later and somewhere
 *   else. Every bounds check here happens *before* the access, because there
 *   is nothing after it that would notice.
 */
final class FfiBuffer
{
    private ?CData $pointer;

    private function __construct(
        public readonly int $size,
        CData $pointer,
    ) {
        $this->pointer = $pointer;
    }

    public static function allocate(int $size): self
    {
        if ($size < 1) {
            throw new NativeMemoryException(sprintf('Cannot allocate %d bytes', $size));
        }

        $pointer = Libc::malloc($size);

        if ($pointer === null) {
            throw new NativeMemoryException(sprintf('malloc(%d) returned null', $size));
        }

        return new self($size, $pointer);
    }

    public function write(int $offset, string $data): void
    {
        $length = strlen($data);
        $this->assertWithinBounds($offset, $length);

        if ($length === 0) {
            return;
        }

        $bytes = Libc::cast('char *', $this->requirePointer());
        FFI::memcpy(FFI::addr($bytes[$offset]), $data, $length);
    }

    public function read(int $offset, int $length): string
    {
        $this->assertWithinBounds($offset, $length);

        if ($length <= 0) {
            return '';
        }

        $bytes = Libc::cast('char *', $this->requirePointer());

        return FFI::string(FFI::addr($bytes[$offset]), $length);
    }

    /**
     * Returns the allocation to libc. Idempotent, which the underlying free()
     * emphatically is not: a second free() of the same address corrupts the
     * allocator and is normally reported by a later, unrelated call.
     */
    public function free(): void
    {
        if ($this->pointer === null) {
            return;
        }

        Libc::free($this->pointer);
        $this->pointer = null;
    }

    public function isAllocated(): bool
    {
        return $this->pointer !== null;
    }

    public function address(): int
    {
        return Libc::addressOf($this->requirePointer());
    }

    /**
     * The safety net, not the plan. PHP frees nothing here on its own, so a
     * buffer that goes out of scope unfreed would leak until the process
     * exits - invisibly, since the PHP counters never showed it.
     */
    public function __destruct()
    {
        $this->free();
    }

    /**
     * Written as three comparisons rather than `$offset + $length > $size`
     * because that sum overflows for large offsets and wraps negative, which
     * turns the check into a guarantee of exactly what it was meant to stop.
     */
    private function assertWithinBounds(int $offset, int $length): void
    {
        if ($offset < 0 || $length < 0) {
            throw new NativeMemoryException(sprintf('Negative offset (%d) or length (%d)', $offset, $length));
        }

        if ($offset > $this->size || $length > $this->size - $offset) {
            throw new NativeMemoryException(sprintf(
                'Access of %d bytes at offset %d runs past the %d-byte buffer',
                $length,
                $offset,
                $this->size,
            ));
        }
    }

    private function requirePointer(): CData
    {
        if ($this->pointer === null) {
            throw new NativeMemoryException('The buffer was already freed');
        }

        return $this->pointer;
    }
}
