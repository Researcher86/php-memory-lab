<?php

declare(strict_types=1);

namespace App\Tests\Ipc;

use App\Ipc\Exception\SharedMemoryException;
use App\Ipc\SharedMemorySegment;
use PHPUnit\Framework\TestCase;

final class SharedMemorySegmentTest extends TestCase
{
    /** @var list<int> */
    private array $keys = [];

    /**
     * Cleanup goes through the key rather than the object, because a detached
     * wrapper can no longer remove anything: the segment is still in the
     * kernel and this process no longer holds a handle to it. Re-attaching
     * first is the only way back to it - which is exactly how a stale segment
     * from a crashed run has to be cleaned up too.
     */
    protected function tearDown(): void
    {
        foreach ($this->keys as $key) {
            SharedMemorySegment::attach($key)->destroy();
        }

        $this->keys = [];
    }

    public function testValuesComeBackAsTheyWentIn(): void
    {
        $segment = $this->segment();

        $segment->put(1, 42);
        $segment->put(2, 'text');
        $segment->put(3, ['nested' => ['a', 'b'], 'n' => 1.5]);

        self::assertSame(42, $segment->get(1));
        self::assertSame('text', $segment->get(2));
        self::assertSame(['nested' => ['a', 'b'], 'n' => 1.5], $segment->get(3));
    }

    public function testWritingTheSameIndexTwiceReplacesTheValue(): void
    {
        $segment = $this->segment();

        $segment->put(1, 'first');
        $segment->put(1, 'second');

        self::assertSame('second', $segment->get(1));
    }

    public function testReadingAnIndexThatWasNeverWrittenThrows(): void
    {
        $segment = $this->segment();

        self::assertFalse($segment->has(7));

        $this->expectException(SharedMemoryException::class);

        $segment->get(7);
    }

    public function testRemovedVariablesAreGone(): void
    {
        $segment = $this->segment();
        $segment->put(1, 'value');

        $segment->remove(1);

        self::assertFalse($segment->has(1));
    }

    /**
     * PHP serializes every value into the segment, so the limit is the size of
     * the serialized form and not of the PHP value. Asking for more than the
     * segment holds fails loudly rather than truncating.
     */
    public function testAValueTooLargeForTheSegmentIsRefused(): void
    {
        $segment = $this->segment(4096);

        $this->expectException(SharedMemoryException::class);

        $segment->put(1, str_repeat('x', 8192));
    }

    public function testDetachingKeepsTheContentsForTheNextAttach(): void
    {
        $key = $this->key();
        $first = SharedMemorySegment::attach($key);
        $first->put(1, 'survives');
        $first->detach();

        self::assertFalse($first->isAttached());

        $second = SharedMemorySegment::attach($key);

        self::assertSame('survives', $second->get(1));
    }

    public function testDestroyingTheSegmentTakesTheContentsWithIt(): void
    {
        $key = $this->key();
        $first = SharedMemorySegment::attach($key);
        $first->put(1, 'temporary');
        $first->destroy();

        $second = SharedMemorySegment::attach($key);

        self::assertFalse($second->has(1));
    }

    public function testAnOperationOnADetachedSegmentThrows(): void
    {
        $segment = $this->segment();
        $segment->detach();

        $this->expectException(SharedMemoryException::class);

        $segment->put(1, 'nowhere');
    }

    /**
     * The point of the whole class: a value written in one process is visible
     * in another, which no amount of PHP memory sharing can do.
     */
    public function testAChildSeesWhatTheParentWrote(): void
    {
        $segment = $this->segment();
        $segment->put(1, 'from the parent');

        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);

        if ($pid === 0) {
            $child = SharedMemorySegment::attach($segment->key);
            $child->put(2, strtoupper((string) $child->get(1)));
            $child->detach();

            exit(0);
        }

        pcntl_waitpid($pid, $status);

        self::assertSame(0, pcntl_wexitstatus($status));
        self::assertSame('FROM THE PARENT', $segment->get(2));
    }

    private function segment(int $size = SharedMemorySegment::DEFAULT_SIZE): SharedMemorySegment
    {
        return SharedMemorySegment::attach($this->key(), $size);
    }

    private function key(): int
    {
        $key = SharedMemorySegment::randomKey();
        $this->keys[] = $key;

        return $key;
    }
}
