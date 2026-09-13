<?php

declare(strict_types=1);

use App\Experiment\Experiment;
use App\Experiment\Options;
use App\Experiment\Output;
use App\Ipc\RingBuffer;
use App\Ipc\SharedMemorySegment;

return new Experiment(
    name: 'ring:pacing',
    description: 'full, empty, backpressure, and the cost of polling',
    supports: ['iterations'],
    run: static function (Options $options, Output $out): void {
        $out->heading('full, empty, and the cost of asking again');

        /*
         * A fixed-size buffer has exactly two interesting states and no way to wait
         * for either of them to change. push() returns false when it is full, pop()
         * returns null when it is empty, and in both cases the caller's only option
         * is to come back later. How much later is the whole design question: too
         * soon burns CPU on finding nothing, too late adds latency to every message.
         */
        $buffer = RingBuffer::create(SharedMemorySegment::randomKey(), 4, 64);

        $out->write("\nA 4-slot buffer, filled and drained by hand:\n");

        for ($i = 1; $i <= 5; $i++) {
            $accepted = $buffer->push('message ' . $i);

            $out->write(sprintf(
                "  push #%d -> %-5s  size %d/%d%s\n",
                $i,
                $accepted ? 'true' : 'false',
                $buffer->size(),
                $buffer->capacity(),
                $accepted ? '' : '   <- full: refused, not overwritten',
            ));
        }

        for ($i = 1; $i <= 5; $i++) {
            $message = $buffer->pop();

            $out->write(sprintf(
                "  pop  #%d -> %-11s size %d/%d%s\n",
                $i,
                $message ?? 'null',
                $buffer->size(),
                $buffer->capacity(),
                $message === null ? '   <- empty: nothing to take' : '',
            ));
        }

        $buffer->destroy();

        /*
         * Refusing to overwrite is what makes a full buffer backpressure rather than
         * data loss: a producer that cannot push has to slow down to the consumer's
         * rate, exactly as a producer blocked in send() does on a socket.
         */
        $messages = $options->iterations(400);
        $consumerDelayUs = 500;
        $buffer = RingBuffer::create(SharedMemorySegment::randomKey(), 8, 64);
        $payload = str_repeat('x', 64);

        $start = hrtime(true);
        $pid = pcntl_fork();

        if ($pid === 0) {
            $consumer = RingBuffer::attach($buffer->key);

            for ($received = 0; $received < $messages;) {
                usleep($consumerDelayUs);

                if ($consumer->pop() !== null) {
                    ++$received;
                }
            }

            exit(0);
        }

        $refusals = 0;

        for ($i = 0; $i < $messages; $i++) {
            while (!$buffer->push($payload)) {
                ++$refusals;
            }
        }

        pcntl_waitpid($pid, $status);
        $elapsedMs = (hrtime(true) - $start) / 1e6;
        $buffer->destroy();

        $out->write(sprintf(
            "\nProducer at full speed, consumer sleeping %d us per message:\n  %d messages in %.1f ms = %.0f/s, against a consumer capable of %.0f/s\n  the producer was refused %s times - once per attempt made while the buffer was full\n",
            $consumerDelayUs,
            $messages,
            $elapsedMs,
            $messages / ($elapsedMs / 1000),
            1_000_000 / $consumerDelayUs,
            number_format($refusals),
        ));

        $out->note('the producer ran at the consumer\'s rate without either of them agreeing to it. That is backpressure, and an 8-slot buffer is the entire mechanism.');

        /*
         * And the cost of the asking. This time the producer is the slow one - a
         * message every 2 ms, the shape of a real event source - so the buffer is
         * usually empty and the consumer's polling interval is what decides how long
         * a message waits before anyone looks at it. Each message carries the time it
         * was pushed, which is how the consumer can measure that.
         */
        $messages = 200;
        $producerGapUs = 2_000;
        $buffer = RingBuffer::create(SharedMemorySegment::randomKey(), 64, 32);

        $out->write(sprintf(
            "\n%d messages, one every %d us, varying only how long the consumer waits between polls:\n\n",
            $messages,
            $producerGapUs,
        ));
        $out->write(sprintf("%12s | %10s | %14s | %s\n", 'poll gap', 'wall', 'mean latency', 'consumer CPU'));
        $out->write(str_repeat('-', 60) . "\n");

        foreach ([0, 100, 1_000, 10_000] as $pollGapUs) {
            $start = hrtime(true);
            $pid = pcntl_fork();

            if ($pid === 0) {
                $consumer = RingBuffer::attach($buffer->key);
                $latencyNs = 0;
                $received = 0;

                while ($received < $messages) {
                    $message = $consumer->pop();

                    if ($message === null) {
                        if ($pollGapUs > 0) {
                            usleep($pollGapUs);
                        }

                        continue;
                    }

                    /** @var array{1: int} $sentAt */
                    $sentAt = unpack('J', $message);
                    $latencyNs += hrtime(true) - $sentAt[1];
                    ++$received;
                }

                $usage = getrusage();
                $cpu = $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6
                    + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6;

                // Reported through the buffer itself, now that it is drained.
                $consumer->push(pack('J', (int) ($latencyNs / $messages)));
                $consumer->push(pack('J', (int) ($cpu * 1e6)));

                exit(0);
            }

            for ($i = 0; $i < $messages; $i++) {
                usleep($producerGapUs);

                while (!$buffer->push(pack('J', hrtime(true)))) {
                }
            }

            pcntl_waitpid($pid, $status);
            $wallMs = (hrtime(true) - $start) / 1e6;

            /** @var array{1: int} $meanLatency */
            $meanLatency = unpack('J', (string) $buffer->pop());
            /** @var array{1: int} $consumerCpu */
            $consumerCpu = unpack('J', (string) $buffer->pop());

            $out->write(sprintf(
                "%12s | %7.1f ms | %11.1f us | %9.1f ms\n",
                $pollGapUs === 0 ? 'spin, no gap' : $pollGapUs . ' us',
                $wallMs,
                $meanLatency[1] / 1000,
                $consumerCpu[1] / 1000,
            ));
        }

        $buffer->destroy();

        $out->note('polling is a dial between latency and CPU, and there is no setting that is good at both. A socket does not have this dial because the kernel wakes the reader exactly when there is something to read - which is the feature being given up in exchange for the copy that shared memory saves.');
        $out->note('a real system closes the gap with something to be woken on: an eventfd, a futex, a semaphore used as a signal rather than a mutex. Each of those is a syscall per message again, which is where the socket already was.');
        $out->context();
    },
);
