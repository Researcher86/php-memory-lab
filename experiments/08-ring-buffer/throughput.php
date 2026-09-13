<?php

declare(strict_types=1);

use App\Experiment\Experiment;
use App\Experiment\Options;
use App\Experiment\Output;
use App\Ipc\Exception\ChannelClosedException;
use App\Ipc\RingBuffer;
use App\Ipc\SharedMemorySegment;
use App\Ipc\SocketChannel;
use App\Memory\ByteFormatter;

return new Experiment(
    name: 'ring:throughput',
    description: 'the ring buffer against a socket, and against itself without the lock',
    supports: ['iterations', 'payload-size'],
    run: static function (Options $options, Output $out): void {
        $out->heading('ring buffer against the socket it replaces');

        /*
         * The same job three ways: one producer process sends N messages to one
         * consumer process, through the semaphore-protected ring buffer, through the
         * Unix socket of phase 5, and through the identical shared-memory layout with
         * the semaphore taken out.
         *
         * The third row is not an API this lab offers - it is the control that says
         * which part of the second row is expensive. Everything else about it is the
         * same code path: same segment, same header, same slots.
         */
        $messages = $options->iterations(20_000);
        // --payload-size narrows the sweep to one size, for when the
        // question is about a specific message rather than about the shape of
        // the curve.
        $sizes = $options->payloadSize(0) > 0 ? [$options->payloadSize(0)] : [64, 1024, 16 * 1024];
        $capacity = 256;

        /**
         * Total CPU seconds burned by this process and everything it has waited for.
         */
        function cpu_seconds(): float
        {
            $self = getrusage();
            $children = getrusage(1);

            return $self['ru_utime.tv_sec'] + $self['ru_utime.tv_usec'] / 1e6
                + $self['ru_stime.tv_sec'] + $self['ru_stime.tv_usec'] / 1e6
                + $children['ru_utime.tv_sec'] + $children['ru_utime.tv_usec'] / 1e6
                + $children['ru_stime.tv_sec'] + $children['ru_stime.tv_usec'] / 1e6;
        }

        /**
         * @return array{wallMs: float, cpuSeconds: float}
         */
        function run_ring(int $messages, int $size, int $capacity): array
        {
            $buffer = RingBuffer::create(SharedMemorySegment::randomKey(), $capacity, $size);
            $payload = str_repeat('x', $size);

            $cpuBefore = cpu_seconds();
            $start = hrtime(true);
            $pid = pcntl_fork();

            if ($pid === 0) {
                $producer = RingBuffer::attach($buffer->key);

                for ($i = 0; $i < $messages; $i++) {
                    // Full is backpressure with no way to wait for it, so: spin. The
                    // isFull() check is deliberately outside the lock - it can be
                    // stale, push() re-checks under the lock anyway, and taking the
                    // lock only to be told "still full" turns polling into a
                    // hand-off of the lock itself, which costs a context switch per
                    // attempt.
                    while ($producer->isFull() || !$producer->push($payload)) {
                    }
                }

                exit(0);
            }

            for ($received = 0; $received < $messages;) {
                if ($buffer->isEmpty()) {
                    continue;
                }

                if ($buffer->pop() !== null) {
                    ++$received;
                }
            }

            pcntl_waitpid($pid, $status);
            $result = ['wallMs' => (hrtime(true) - $start) / 1e6, 'cpuSeconds' => cpu_seconds() - $cpuBefore];
            $buffer->destroy();

            return $result;
        }

        /**
         * @return array{wallMs: float, cpuSeconds: float}
         */
        function run_socket(int $messages, int $size): array
        {
            [$consumer, $producerEnd] = SocketChannel::pair();
            $payload = str_repeat('x', $size);

            $cpuBefore = cpu_seconds();
            $start = hrtime(true);
            $pid = pcntl_fork();

            if ($pid === 0) {
                $consumer->close();

                try {
                    for ($i = 0; $i < $messages; $i++) {
                        $producerEnd->send($payload);
                    }
                } catch (ChannelClosedException) {
                    // consumer gone; nothing left to send
                }

                $producerEnd->close();

                exit(0);
            }

            $producerEnd->close();

            for ($received = 0; $received < $messages; $received++) {
                $consumer->receive();
            }

            pcntl_waitpid($pid, $status);
            $result = ['wallMs' => (hrtime(true) - $start) / 1e6, 'cpuSeconds' => cpu_seconds() - $cpuBefore];
            $consumer->close();

            return $result;
        }

        /**
         * The same ring, with no lock at all and no safety net.
         *
         * Correct only because there is exactly one producer and exactly one
         * consumer: the producer owns the write position, the consumer owns the read
         * position, neither writes the other's field, and each publishes its new
         * position only after the data it describes is already in the segment. It
         * also assumes a 4-byte aligned store is seen whole by the other process,
         * which is true on x86-64 and is not a guarantee PHP makes anywhere.
         *
         * What it cannot do is notice that the other side died in the middle of a
         * slot. That is the trade RingBuffer makes, and the row below is its price.
         *
         * @return array{wallMs: float, cpuSeconds: float}
         */
        function run_unsynchronized_ring(int $messages, int $size, int $capacity): array
        {
            $key = SharedMemorySegment::randomKey();
            $slotStride = $size + 4;
            $segment = shmop_open($key, 'c', 0666, 32 + $capacity * $slotStride);

            if ($segment === false) {
                throw new RuntimeException('unable to create the unsynchronized segment');
            }

            shmop_write($segment, pack('N8', 0, 0, $capacity, $size, 0, 0, 0, 0), 0);
            $payload = str_repeat('x', $size);

            $position = static function (\Shmop $segment, int $offset): int {
                /** @var array{1: int} $field */
                $field = unpack('N', shmop_read($segment, $offset, 4));

                return $field[1];
            };

            $cpuBefore = cpu_seconds();
            $start = hrtime(true);
            $pid = pcntl_fork();

            if ($pid === 0) {
                $own = shmop_open($key, 'w', 0, 0);

                if ($own === false) {
                    exit(1);
                }

                for ($i = 0; $i < $messages; $i++) {
                    // One slot is left unused so that "write == read" can mean empty
                    // and nothing else - the classic way to tell a full ring from an
                    // empty one without a separate counter both sides would have to
                    // agree on.
                    while (($write = $position($own, 20)) !== -1 && ($write + 1) % $capacity === $position($own, 16)) {
                    }

                    shmop_write($own, pack('N', $size) . $payload, 32 + $write * $slotStride);
                    shmop_write($own, pack('N', ($write + 1) % $capacity), 20);
                }

                exit(0);
            }

            for ($received = 0; $received < $messages;) {
                $read = $position($segment, 16);

                if ($read === $position($segment, 20)) {
                    continue;
                }

                /** @var array{1: int} $prefix */
                $prefix = unpack('N', shmop_read($segment, 32 + $read * $slotStride, 4));
                shmop_read($segment, 32 + $read * $slotStride + 4, $prefix[1]);
                shmop_write($segment, pack('N', ($read + 1) % $capacity), 16);
                ++$received;
            }

            pcntl_waitpid($pid, $status);
            $result = ['wallMs' => (hrtime(true) - $start) / 1e6, 'cpuSeconds' => cpu_seconds() - $cpuBefore];
            shmop_delete($segment);

            return $result;
        }

        $out->write(sprintf(
            "\n%s messages per run, ring capacity %d slots.\n\n",
            number_format($messages),
            $capacity,
        ));
        $out->write(sprintf(
            "%9s | %-13s | %10s | %14s | %11s | %s\n",
            'message',
            'transport',
            'wall',
            'messages/s',
            'throughput',
            'CPU seconds',
        ));
        $out->write(str_repeat('-', 86) . "\n");

        foreach ($sizes as $size) {
            foreach ([
                'ring + lock' => static fn (): array => run_ring($messages, $size, $capacity),
                'unix socket' => static fn (): array => run_socket($messages, $size),
                'ring, no lock' => static fn (): array => run_unsynchronized_ring($messages, $size, $capacity),
            ] as $name => $run) {
                $result = $run();

                $out->write(sprintf(
                    "%9s | %-13s | %7.1f ms | %14s | %9s/s | %.2f\n",
                    ByteFormatter::format($size),
                    $name,
                    $result['wallMs'],
                    number_format($messages / ($result['wallMs'] / 1000)),
                    ByteFormatter::format((int) ($messages * $size / ($result['wallMs'] / 1000))),
                    $result['cpuSeconds'],
                ));
            }
        }

        $out->note('the shared memory is not the slow part. The same layout without the semaphore is the fastest row in the table - it moves the payload once, where the socket copies it into the kernel buffer and out again.');
        $out->note('the lock is. One acquire and one release per message, between two processes that always want it at the same time, means most acquires find it held - and an acquire that waits is a sleep and a wake, tens of microseconds against the ~8 us the operation itself costs.');
        $out->note('which is the honest summary of this phase: shared memory buys a copy, and the synchronization needed to make it safe costs more than the copy. The socket wins because the kernel does the synchronizing as a side effect of moving the bytes, and charges once for both.');
        $out->note('the ways out are all real and all cost something: batch many messages per lock, use a lock-free single-producer/single-consumer design and give up crash detection, or keep the lock and accept the rate. None of them is free, and the last column is why.');
        $out->context();
    },
);
