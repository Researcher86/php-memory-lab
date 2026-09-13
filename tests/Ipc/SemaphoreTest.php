<?php

declare(strict_types=1);

namespace App\Tests\Ipc;

use App\Ipc\Exception\SemaphoreException;
use App\Ipc\Semaphore;
use App\Ipc\SharedMemorySegment;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SemaphoreTest extends TestCase
{
    /** @var list<Semaphore> */
    private array $semaphores = [];

    /** @var list<int> */
    private array $segmentKeys = [];

    protected function tearDown(): void
    {
        foreach ($this->semaphores as $semaphore) {
            $semaphore->remove();
        }

        // By key: a detached wrapper cannot remove the segment behind it.
        foreach ($this->segmentKeys as $key) {
            SharedMemorySegment::attach($key)->destroy();
        }

        $this->semaphores = [];
        $this->segmentKeys = [];
    }

    public function testAcquireAndReleaseCanBeRepeated(): void
    {
        $semaphore = $this->semaphore();

        $semaphore->acquire();
        $semaphore->release();
        $semaphore->acquire();
        $semaphore->release();

        self::assertTrue($semaphore->tryAcquire());
        $semaphore->release();
    }

    public function testSynchronizedReturnsWhatTheCriticalSectionReturned(): void
    {
        $semaphore = $this->semaphore();

        self::assertSame(7, $semaphore->synchronized(static fn (): int => 7));
    }

    /**
     * The reason synchronized() exists. A lock released only on the happy
     * path stops being released the first time anything goes wrong, and the
     * next acquirer waits forever.
     */
    public function testSynchronizedReleasesEvenWhenTheBodyThrows(): void
    {
        $semaphore = $this->semaphore();

        try {
            $semaphore->synchronized(static function (): void {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // expected
        }

        self::assertTrue($semaphore->tryAcquire(), 'semaphore was left held');
        $semaphore->release();
    }

    public function testUsingARemovedSemaphoreThrows(): void
    {
        $semaphore = $this->semaphore();
        $semaphore->remove();

        $this->expectException(SemaphoreException::class);

        $semaphore->acquire();
    }

    /**
     * Two children incrementing the same shared counter. Under the semaphore
     * the read-modify-write is indivisible, so the total is exact - this is
     * the controlled version of experiments/07-shared-memory/race-condition.php.
     */
    public function testAProtectedCounterLosesNoUpdatesAcrossProcesses(): void
    {
        $key = SharedMemorySegment::randomKey();
        $this->segmentKeys[] = $key;
        $segment = SharedMemorySegment::attach($key);
        $semaphore = $this->semaphore();
        $segment->put(1, 0);

        $children = 2;
        $increments = 100;
        $pids = [];

        for ($c = 0; $c < $children; $c++) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);

            if ($pid === 0) {
                $ownSegment = SharedMemorySegment::attach($segment->key);
                $ownSemaphore = Semaphore::attach($semaphore->key);

                for ($i = 0; $i < $increments; $i++) {
                    $ownSemaphore->synchronized(static function () use ($ownSegment): void {
                        $ownSegment->put(1, (int) $ownSegment->get(1) + 1);
                    });
                }

                $ownSegment->detach();

                exit(0);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));
        }

        self::assertSame($children * $increments, $segment->get(1));
    }

    private function semaphore(): Semaphore
    {
        $semaphore = Semaphore::attach(SharedMemorySegment::randomKey());
        $this->semaphores[] = $semaphore;

        return $semaphore;
    }
}
