<?php

declare(strict_types=1);

namespace App\Ipc;

use App\Ipc\Exception\SemaphoreException;
use SysvSemaphore;
use Throwable;

/**
 * A System V semaphore: the lock that shared memory does not come with.
 *
 * Shared memory gives two processes the same bytes and no opinion about who
 * may touch them when. A counter incremented by several processes at once is
 * a read, an add and a write with two gaps in it, and every gap is an update
 * some other process can overwrite. The semaphore closes the gaps.
 *
 * Like a segment, it lives in the kernel under a key and outlives its
 * creator, so remove() matters. Unlike a segment, it has a safety net the
 * kernel provides: PHP passes SEM_UNDO on every acquire, so a process that
 * dies while holding the lock releases it on the way out - even under
 * SIGKILL - instead of wedging everyone else forever. A lock flag written
 * into shared memory by hand has no equivalent.
 */
final class Semaphore
{
    private ?SysvSemaphore $handle;

    private function __construct(
        public readonly int $key,
        public readonly bool $autoRelease,
        SysvSemaphore $handle,
    ) {
        $this->handle = $handle;
    }

    /**
     * @param int  $maxAcquire  how many holders are allowed at once; 1 is a mutex
     * @param bool $autoRelease release the semaphore at PHP's request
     *                          shutdown. Distinct from the kernel's SEM_UNDO,
     *                          which PHP passes on every acquire regardless;
     *                          this one only matters where a process outlives
     *                          a request, which CLI never does.
     */
    public static function attach(
        int $key,
        int $maxAcquire = 1,
        int $permissions = 0o666,
        bool $autoRelease = true,
    ): self {
        $handle = @sem_get($key, $maxAcquire, $permissions, $autoRelease);

        if ($handle === false) {
            throw new SemaphoreException(\sprintf('Unable to get semaphore 0x%x', $key));
        }

        return new self($key, $autoRelease, $handle);
    }

    /** Blocks until the semaphore can be held. */
    public function acquire(): void
    {
        if (!@sem_acquire($this->requireHandle())) {
            throw new SemaphoreException(\sprintf('Unable to acquire semaphore 0x%x', $this->key));
        }
    }

    /** Returns false instead of waiting when the semaphore is taken. */
    public function tryAcquire(): bool
    {
        return @sem_acquire($this->requireHandle(), true);
    }

    public function release(): void
    {
        if (!@sem_release($this->requireHandle())) {
            throw new SemaphoreException(\sprintf('Unable to release semaphore 0x%x', $this->key));
        }
    }

    /**
     * Runs $critical while holding the semaphore, and releases it even if
     * $critical throws. The finally is the entire point: a lock released only
     * on the happy path is a lock that stops being released the first time
     * anything goes wrong.
     *
     * @template T
     *
     * @param callable(): T $critical
     *
     * @return T
     *
     * @throws Throwable whatever $critical threw, after the release
     */
    public function synchronized(callable $critical): mixed
    {
        $this->acquire();

        try {
            return $critical();
        } finally {
            $this->release();
        }
    }

    /** Removes the semaphore from the kernel. Idempotent. */
    public function remove(): void
    {
        if ($this->handle === null) {
            return;
        }

        @sem_remove($this->handle);
        $this->handle = null;
    }

    private function requireHandle(): SysvSemaphore
    {
        if ($this->handle === null) {
            throw new SemaphoreException(\sprintf('Semaphore 0x%x was already removed', $this->key));
        }

        return $this->handle;
    }
}
