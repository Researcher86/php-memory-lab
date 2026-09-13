<?php

declare(strict_types=1);

namespace App\Ipc;

use App\Ipc\Exception\RingBufferCorruptedException;
use App\Ipc\Exception\RingBufferException;
use Shmop;

/**
 * A fixed-size ring buffer in raw shared memory, for one producer and one
 * consumer.
 *
 * This is the phase where the socket has to be rebuilt by hand. A segment
 * gives bytes and nothing else: no message boundaries, no ordering, no notion
 * of "full", no backpressure, and no way to know that a writer has died. The
 * layout below supplies each of those, and how much of it there is, is the
 * point of the exercise.
 *
 *   header (32 B)                        data area (capacity slots)
 *   ┌───────┬─────────┬─────...─┬──────┐ ┌────────────┬────────────┬───...
 *   │ magic │ version │         │ busy │ │   slot 0   │   slot 1   │
 *   └───────┴─────────┴─────...─┴──────┘ └────────────┴────────────┴───...
 *    0       4                   28       └ 4-byte length, then that many bytes
 *
 * Every header field is a 32-bit big-endian unsigned integer, so the segment
 * means the same thing to any process that opens it - the same reason a
 * network protocol fixes its byte order.
 *
 * Deliberately out of scope, because each would change the design rather than
 * extend it: multiple producers or consumers, lock-free operation, resizing,
 * variable-size zero-copy messages, and repairing the contents after a writer
 * dies mid-update. The last one is detected instead - see $busyPid.
 *
 * Raw bytes, and therefore ext-shmop rather than the sysvshm functions used
 * elsewhere in this directory: shm_put_var() serializes each value and keeps
 * its own variable directory, which is precisely the layer this class exists
 * to replace.
 *
 * @phpstan-type Header array{
 *     magic: int,
 *     version: int,
 *     capacity: int,
 *     slotSize: int,
 *     readPosition: int,
 *     writePosition: int,
 *     count: int,
 *     busyPid: int,
 * }
 */
final class RingBuffer
{
    /** "RBUF", so a segment holding something else is recognised as such. */
    public const MAGIC = 0x52425546;

    public const VERSION = 1;

    /** magic, version, capacity, slotSize, readPosition, writePosition, count, busyPid. */
    private const HEADER_SIZE = 32;

    /**
     * The mutable tail of the header - read position, write position, count
     * and the busy flag, sixteen bytes - written back in one call so that an
     * operation commits all four or none of them.
     */
    private const OFFSET_MUTABLE = 16;

    private const OFFSET_BUSY_PID = 28;

    /** Nobody is mid-update; the header and the data agree. */
    private const READY = 0;

    /** Length prefix in front of the message inside each slot. */
    private const LENGTH_PREFIX = 4;

    private ?Shmop $segment;

    private function __construct(
        public readonly int $key,
        Shmop $segment,
        private readonly Semaphore $semaphore,
    ) {
        $this->segment = $segment;
    }

    /**
     * Formats a new buffer, replacing whatever was under $key before.
     *
     * @param int $capacity number of slots
     * @param int $slotSize largest message a slot can hold, in bytes
     */
    public static function create(int $key, int $capacity, int $slotSize): self
    {
        if ($capacity < 1 || $slotSize < 1) {
            throw new RingBufferException('A ring buffer needs at least one slot of at least one byte');
        }

        // A leftover buffer from a previous run would keep its old capacity,
        // and shmop_open() cannot grow an existing segment - it would hand
        // back the small one and the data area would be silently short. The
        // semaphore goes with it: a fresh buffer should not inherit the lock
        // state of whatever ran here before.
        self::deleteSegment($key);
        Semaphore::attach($key)->remove();

        $size = self::HEADER_SIZE + $capacity * ($slotSize + self::LENGTH_PREFIX);
        $segment = @shmop_open($key, 'c', 0o666, $size);

        if ($segment === false) {
            throw new RingBufferException(sprintf('Unable to create a %d-byte segment for 0x%x', $size, $key));
        }

        $buffer = new self($key, $segment, Semaphore::attach($key));
        $buffer->write(0, pack('N8', self::MAGIC, self::VERSION, $capacity, $slotSize, 0, 0, 0, self::READY));

        return $buffer;
    }

    /**
     * Opens an existing buffer. The magic and version checks are what stop a
     * stale or foreign segment from being read as a valid one - four bytes of
     * somebody else's data taken for a capacity is how a ring buffer comes to
     * iterate over slots that were never there.
     */
    public static function attach(int $key): self
    {
        $segment = @shmop_open($key, 'w', 0, 0);

        if ($segment === false) {
            throw new RingBufferException(sprintf('No ring buffer at 0x%x', $key));
        }

        if (shmop_size($segment) < self::HEADER_SIZE) {
            throw new RingBufferCorruptedException(sprintf('Segment 0x%x is too small to hold a header', $key));
        }

        // Validated before the semaphore is attached, and not after: sem_get()
        // creates the semaphore if it is missing, so attaching first and
        // throwing second would leave one behind for every foreign segment
        // this is ever pointed at.
        $header = self::readHeaderFrom($segment);

        if ($header['magic'] !== self::MAGIC) {
            throw new RingBufferCorruptedException(sprintf(
                'Segment 0x%x is not a ring buffer (magic 0x%08x, expected 0x%08x)',
                $key,
                $header['magic'],
                self::MAGIC,
            ));
        }

        if ($header['version'] !== self::VERSION) {
            throw new RingBufferCorruptedException(sprintf(
                'Ring buffer 0x%x is version %d, this code speaks version %d',
                $key,
                $header['version'],
                self::VERSION,
            ));
        }

        return new self($key, $segment, Semaphore::attach($key));
    }

    /**
     * Appends a message, or returns false when the buffer is full. The caller
     * decides whether to wait, drop or slow down - a fixed-size buffer offers
     * no fourth option, which is exactly what makes it backpressure.
     *
     * @throws RingBufferException when the message is larger than a slot
     */
    public function push(string $message): bool
    {
        $length = strlen($message);

        /** @var bool $accepted */
        $accepted = $this->transaction(function (array $header) use ($message, $length): array {
            if ($length > $header['slotSize']) {
                throw new RingBufferException(sprintf(
                    'Message of %d bytes exceeds the %d-byte slot',
                    $length,
                    $header['slotSize'],
                ));
            }

            if ($header['count'] >= $header['capacity']) {
                return [false, $header];
            }

            $this->write($this->slotOffset($header, $header['writePosition']), pack('N', $length) . $message);
            $header['writePosition'] = ($header['writePosition'] + 1) % $header['capacity'];
            ++$header['count'];

            return [true, $header];
        });

        return $accepted;
    }

    /**
     * Takes the oldest message, or null when the buffer is empty. Empty is a
     * state and not an error: it is the normal condition of a consumer that is
     * keeping up.
     */
    public function pop(): ?string
    {
        /** @var string|null $message */
        $message = $this->transaction(function (array $header): array {
            if ($header['count'] === 0) {
                return [null, $header];
            }

            $offset = $this->slotOffset($header, $header['readPosition']);
            /** @var array{1: int} $prefix */
            $prefix = unpack('N', $this->read($offset, self::LENGTH_PREFIX));
            $length = $prefix[1];

            if ($length > $header['slotSize']) {
                throw new RingBufferCorruptedException(sprintf(
                    'Slot %d declares %d bytes in a %d-byte slot',
                    $header['readPosition'],
                    $length,
                    $header['slotSize'],
                ));
            }

            $message = $this->read($offset + self::LENGTH_PREFIX, $length);
            $header['readPosition'] = ($header['readPosition'] + 1) % $header['capacity'];
            --$header['count'];

            return [$message, $header];
        });

        return $message;
    }

    public function size(): int
    {
        return $this->readHeader()['count'];
    }

    public function capacity(): int
    {
        return $this->readHeader()['capacity'];
    }

    public function slotSize(): int
    {
        return $this->readHeader()['slotSize'];
    }

    public function isEmpty(): bool
    {
        return $this->readHeader()['count'] === 0;
    }

    public function isFull(): bool
    {
        $header = $this->readHeader();

        return $header['count'] >= $header['capacity'];
    }

    /**
     * The pid that was mid-update when it stopped, or 0 when the buffer is
     * consistent. Anything else means a process died between two writes that
     * were supposed to happen together.
     */
    public function busyPid(): int
    {
        return $this->readHeader()['busyPid'];
    }

    /** Removes the segment and the semaphore. Idempotent. */
    public function destroy(): void
    {
        if ($this->segment === null) {
            return;
        }

        @shmop_delete($this->segment);
        $this->segment = null;
        $this->semaphore->remove();
    }

    /**
     * Runs $operation under the semaphore, with the header marked as
     * mid-update for its duration, and commits the header it returns.
     *
     * The mark is the crash-consistency mechanism. The kernel releases the
     * semaphore of a process that dies (SEM_UNDO), so the next process gets
     * the lock cleanly and would otherwise find a buffer whose count says one
     * thing and whose slots say another, indistinguishable from a healthy
     * one. A busy pid that is no longer running is that distinction. Nothing
     * is repaired - a half-written slot cannot be reconstructed - the buffer
     * is refused.
     *
     * The header is read in one call and written back in one call. That is
     * partly for speed, and partly so that positions, count and the flag can
     * never be seen half-updated: the 16 mutable bytes land together.
     *
     * @phpstan-param callable(Header): array{0: mixed, 1: Header} $operation
     */
    private function transaction(callable $operation): mixed
    {
        return $this->semaphore->synchronized(function () use ($operation): mixed {
            $header = $this->readHeader();

            if ($header['busyPid'] !== self::READY) {
                throw new RingBufferCorruptedException(sprintf(
                    'Ring buffer 0x%x was left mid-update by pid %d',
                    $this->key,
                    $header['busyPid'],
                ));
            }

            $this->write(self::OFFSET_BUSY_PID, pack('N', posix_getpid()));
            $committed = $header;

            try {
                [$result, $committed] = $operation($header);

                return $result;
            } finally {
                // On the exception path $committed is still the header as it
                // was read, so the positions roll back and the flag clears -
                // whatever was written into a slot is simply not claimed.
                $this->commit($committed);
            }
        });
    }

    /**
     * @phpstan-param Header $header
     */
    private function commit(array $header): void
    {
        $this->write(self::OFFSET_MUTABLE, pack(
            'N4',
            $header['readPosition'],
            $header['writePosition'],
            $header['count'],
            self::READY,
        ));
    }

    /**
     * @phpstan-return Header
     */
    private function readHeader(): array
    {
        return self::readHeaderFrom($this->requireSegment());
    }

    /**
     * @phpstan-return Header
     */
    private static function readHeaderFrom(Shmop $segment): array
    {
        /** @var array{1: int, 2: int, 3: int, 4: int, 5: int, 6: int, 7: int, 8: int} $fields */
        $fields = unpack('N8', shmop_read($segment, 0, self::HEADER_SIZE));

        return [
            'magic' => $fields[1],
            'version' => $fields[2],
            'capacity' => $fields[3],
            'slotSize' => $fields[4],
            'readPosition' => $fields[5],
            'writePosition' => $fields[6],
            'count' => $fields[7],
            'busyPid' => $fields[8],
        ];
    }

    /**
     * @phpstan-param Header $header
     */
    private function slotOffset(array $header, int $position): int
    {
        return self::HEADER_SIZE + $position * ($header['slotSize'] + self::LENGTH_PREFIX);
    }

    private function read(int $offset, int $length): string
    {
        if ($length === 0) {
            return '';
        }

        $data = @shmop_read($this->requireSegment(), $offset, $length);

        if (strlen($data) !== $length) {
            throw new RingBufferCorruptedException(sprintf(
                'Read %d of %d bytes at offset %d in 0x%x',
                strlen($data),
                $length,
                $offset,
                $this->key,
            ));
        }

        return $data;
    }

    private function write(int $offset, string $data): void
    {
        $written = @shmop_write($this->requireSegment(), $data, $offset);

        if ($written !== strlen($data)) {
            throw new RingBufferException(sprintf(
                'Wrote %d of %d bytes at offset %d in 0x%x',
                $written,
                strlen($data),
                $offset,
                $this->key,
            ));
        }
    }

    private function requireSegment(): Shmop
    {
        if ($this->segment === null) {
            throw new RingBufferException(sprintf('Ring buffer 0x%x was destroyed', $this->key));
        }

        return $this->segment;
    }

    private static function deleteSegment(int $key): void
    {
        $existing = @shmop_open($key, 'w', 0, 0);

        if ($existing !== false) {
            @shmop_delete($existing);
        }
    }
}
