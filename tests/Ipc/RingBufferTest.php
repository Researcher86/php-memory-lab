<?php

declare(strict_types=1);

namespace App\Tests\Ipc;

use App\Ipc\Exception\RingBufferCorruptedException;
use App\Ipc\Exception\RingBufferException;
use App\Ipc\RingBuffer;
use App\Ipc\SharedMemorySegment;
use PHPUnit\Framework\TestCase;

final class RingBufferTest extends TestCase
{
    /** @var list<RingBuffer> */
    private array $buffers = [];

    protected function tearDown(): void
    {
        foreach ($this->buffers as $buffer) {
            $buffer->destroy();
        }

        $this->buffers = [];
    }

    public function testANewBufferIsEmptyAndKnowsItsShape(): void
    {
        $buffer = $this->buffer(4, 64);

        self::assertTrue($buffer->isEmpty());
        self::assertFalse($buffer->isFull());
        self::assertSame(0, $buffer->size());
        self::assertSame(4, $buffer->capacity());
        self::assertSame(64, $buffer->slotSize());
    }

    public function testMessagesComeOutInTheOrderTheyWentIn(): void
    {
        $buffer = $this->buffer(4, 64);

        $buffer->push('first');
        $buffer->push('second');
        $buffer->push('third');

        self::assertSame('first', $buffer->pop());
        self::assertSame('second', $buffer->pop());
        self::assertSame('third', $buffer->pop());
        self::assertNull($buffer->pop());
    }

    public function testAnEmptyMessageIsAMessage(): void
    {
        $buffer = $this->buffer(2, 16);

        self::assertTrue($buffer->push(''));
        self::assertSame('', $buffer->pop());
    }

    public function testTheBufferFillsAndRefusesTheNextMessage(): void
    {
        $buffer = $this->buffer(3, 16);

        self::assertTrue($buffer->push('a'));
        self::assertTrue($buffer->push('b'));
        self::assertTrue($buffer->push('c'));

        self::assertTrue($buffer->isFull());
        self::assertFalse($buffer->push('d'), 'a full buffer must refuse rather than overwrite');
        self::assertSame(3, $buffer->size());
    }

    public function testPoppingFromAnEmptyBufferIsNotAnError(): void
    {
        self::assertNull($this->buffer(2, 16)->pop());
    }

    /**
     * The "ring" part: write position 3 wraps to 0 while the reader is still
     * behind it, and the order has to survive the seam.
     */
    public function testTheWritePositionWrapsWithoutDisturbingTheOrder(): void
    {
        $buffer = $this->buffer(3, 16);
        $seen = [];

        for ($i = 0; $i < 10; $i++) {
            $buffer->push('message ' . $i);

            if ($i % 2 === 1) {
                $seen[] = $buffer->pop();
                $seen[] = $buffer->pop();
            }
        }

        while (($message = $buffer->pop()) !== null) {
            $seen[] = $message;
        }

        $expected = [];

        for ($i = 0; $i < 10; $i++) {
            $expected[] = 'message ' . $i;
        }

        self::assertSame($expected, array_values(array_filter($seen, static fn (?string $m): bool => $m !== null)));
    }

    public function testAMessageLargerThanASlotIsRefused(): void
    {
        $buffer = $this->buffer(2, 8);

        $this->expectException(RingBufferException::class);

        $buffer->push(str_repeat('x', 9));
    }

    public function testCreatingReplacesAnOlderBufferUnderTheSameKey(): void
    {
        $key = SharedMemorySegment::randomKey();
        $old = RingBuffer::create($key, 2, 16);
        $old->push('previous run');

        $new = RingBuffer::create($key, 8, 32);
        $this->buffers[] = $new;

        self::assertTrue($new->isEmpty());
        self::assertSame(8, $new->capacity());
    }

    public function testAttachingToNothingThrows(): void
    {
        $this->expectException(RingBufferException::class);

        RingBuffer::attach(SharedMemorySegment::randomKey());
    }

    /**
     * Four bytes of somebody else's data read as a capacity is how a buffer
     * ends up iterating over slots that were never there.
     */
    public function testAttachingToASegmentThatIsNotARingBufferThrows(): void
    {
        $key = SharedMemorySegment::randomKey();
        $foreign = shmop_open($key, 'c', 0o666, 128);
        self::assertNotFalse($foreign);
        shmop_write($foreign, str_repeat("\x01", 128), 0);

        try {
            $this->expectException(RingBufferCorruptedException::class);
            $this->expectExceptionMessage('is not a ring buffer');

            RingBuffer::attach($key);
        } finally {
            shmop_delete($foreign);
        }
    }

    public function testAVersionThisCodeDoesNotSpeakIsRefused(): void
    {
        $buffer = $this->buffer(2, 16);
        $this->pokeHeader($buffer->key, self::OFFSET_VERSION, RingBuffer::VERSION + 1);

        $this->expectException(RingBufferCorruptedException::class);
        $this->expectExceptionMessage('version');

        RingBuffer::attach($buffer->key);
    }

    /**
     * A header left mid-update. The kernel gives the semaphore back when its
     * holder dies (SEM_UNDO), so the next process gets a lock over a buffer
     * whose count and positions no longer agree - and nothing but this flag
     * tells it apart from a healthy one.
     *
     * Written straight into the header rather than by killing a process at
     * the right microsecond: the state field is the contract being tested,
     * and a test that has to win a race is a test that fails on a slow day.
     * experiments/08-ring-buffer/failure-modes.php does the live version.
     */
    public function testABufferLeftMidUpdateIsRefusedRatherThanRead(): void
    {
        $buffer = $this->buffer(2, 16);
        $buffer->push('before crash');

        $this->pokeHeader($buffer->key, self::OFFSET_STATE, 999_999);

        self::assertSame(999_999, $buffer->busyPid());

        $this->expectException(RingBufferCorruptedException::class);
        $this->expectExceptionMessage('mid-update');

        $buffer->pop();
    }

    /**
     * The shape it is built for: one producer, one consumer, two processes.
     */
    public function testAProducerAndAConsumerInSeparateProcessesExchangeEveryMessage(): void
    {
        $buffer = $this->buffer(8, 32);
        $messages = 200;

        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);

        if ($pid === 0) {
            $producer = RingBuffer::attach($buffer->key);

            for ($i = 0; $i < $messages; $i++) {
                // A full buffer is backpressure, and polling is how a
                // producer without a blocking primitive waits for room.
                while (!$producer->push('message ' . $i)) {
                    usleep(100);
                }
            }

            exit(0);
        }

        $received = [];

        while (count($received) < $messages) {
            $message = $buffer->pop();

            if ($message === null) {
                usleep(100);

                continue;
            }

            $received[] = $message;
        }

        pcntl_waitpid($pid, $status);

        self::assertSame(0, pcntl_wexitstatus($status));
        self::assertSame('message 0', $received[0]);
        self::assertSame('message ' . ($messages - 1), $received[$messages - 1]);
        self::assertCount($messages, $received);
    }

    private const OFFSET_VERSION = 4;
    private const OFFSET_STATE = 28;

    private function buffer(int $capacity, int $slotSize): RingBuffer
    {
        $buffer = RingBuffer::create(SharedMemorySegment::randomKey(), $capacity, $slotSize);
        $this->buffers[] = $buffer;

        return $buffer;
    }

    /** Writes one header field directly, to produce states a healthy run cannot. */
    private function pokeHeader(int $key, int $offset, int $value): void
    {
        $raw = shmop_open($key, 'w', 0, 0);
        self::assertNotFalse($raw);
        shmop_write($raw, pack('N', $value), $offset);
    }
}
