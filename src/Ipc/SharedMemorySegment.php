<?php

declare(strict_types=1);

namespace App\Ipc;

use App\Ipc\Exception\SharedMemoryException;
use SysvSharedMemory;

/**
 * A System V shared-memory segment, addressed by a numeric key.
 *
 * Two things about it are easy to get wrong and both are the reason this
 * wrapper exists rather than bare shm_* calls.
 *
 * It is not a shared byte buffer. PHP stores each variable through
 * serialize(), so put() writes a serialized copy and get() builds a fresh
 * PHP value from it. Two processes never share a zval, only bytes that
 * happen to describe one - which means a read-modify-write is three
 * operations with a gap in the middle, and the gap is where the lost updates
 * of experiments/07-shared-memory/race-condition.php come from.
 *
 * It outlives every process that touches it. detach() drops this process's
 * handle; the segment stays in the kernel, visible in /proc/sysvipc/shm and
 * `ipcs -m`, until someone calls destroy() or the machine reboots. A crashed
 * process leaves its segment behind, and the next run attaching the same key
 * inherits whatever was in it.
 */
final class SharedMemorySegment
{
    public const DEFAULT_SIZE = 64 * 1024;

    private ?SysvSharedMemory $handle;

    private function __construct(
        public readonly int $key,
        SysvSharedMemory $handle,
    ) {
        $this->handle = $handle;
    }

    /**
     * Attaches to the segment for $key, creating it at $size bytes if it does
     * not exist yet. An existing segment keeps its original size - the kernel
     * ignores the request, which is why a size change needs a destroy() first.
     */
    public static function attach(
        int $key,
        int $size = self::DEFAULT_SIZE,
        int $permissions = 0o666,
    ): self {
        $handle = @shm_attach($key, $size, $permissions);

        if ($handle === false) {
            throw new SharedMemoryException(\sprintf(
                'Unable to attach shared memory segment 0x%x (%d bytes)',
                $key,
                $size,
            ));
        }

        return new self($key, $handle);
    }

    /**
     * A key nothing else is using. ftok() derives one from a path, which is
     * how independent programs agree on a segment; a random key is for the
     * opposite case - a test or an experiment that wants a segment of its own.
     */
    public static function randomKey(): int
    {
        return random_int(0x1000_0000, 0x7fff_ffff);
    }

    /**
     * Serializes $value into the segment under $index.
     *
     * @throws SharedMemoryException when the segment has no room left
     */
    public function put(int $index, mixed $value): void
    {
        if (!@shm_put_var($this->requireHandle(), $index, $value)) {
            throw new SharedMemoryException(\sprintf(
                'Unable to write variable %d into segment 0x%x - too large for the segment?',
                $index,
                $this->key,
            ));
        }
    }

    /**
     * @throws SharedMemoryException when $index was never written
     */
    public function get(int $index): mixed
    {
        $handle = $this->requireHandle();

        if (!shm_has_var($handle, $index)) {
            throw new SharedMemoryException(\sprintf(
                'Segment 0x%x holds no variable %d',
                $this->key,
                $index,
            ));
        }

        return @shm_get_var($handle, $index);
    }

    public function has(int $index): bool
    {
        return shm_has_var($this->requireHandle(), $index);
    }

    public function remove(int $index): void
    {
        if (!@shm_remove_var($this->requireHandle(), $index)) {
            throw new SharedMemoryException(\sprintf(
                'Unable to remove variable %d from segment 0x%x',
                $index,
                $this->key,
            ));
        }
    }

    /**
     * Drops this process's handle. The segment and its contents survive -
     * that is the whole difference between detaching and destroying.
     */
    public function detach(): void
    {
        if ($this->handle === null) {
            return;
        }

        shm_detach($this->handle);
        $this->handle = null;
    }

    /**
     * Removes the segment from the kernel. Every other process still attached
     * keeps working until it detaches, but no new attach to this key will
     * find the old contents. Idempotent, so cleanup paths can call it blindly.
     */
    public function destroy(): void
    {
        if ($this->handle === null) {
            return;
        }

        @shm_remove($this->handle);
        $this->handle = null;
    }

    public function isAttached(): bool
    {
        return $this->handle !== null;
    }

    private function requireHandle(): SysvSharedMemory
    {
        if ($this->handle === null) {
            throw new SharedMemoryException(\sprintf('Segment 0x%x is no longer attached', $this->key));
        }

        return $this->handle;
    }
}
