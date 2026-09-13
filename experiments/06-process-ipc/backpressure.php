<?php

declare(strict_types=1);

use App\Experiment\Experiment;
use App\Experiment\Options;
use App\Experiment\Output;
use App\Ipc\Exception\ChannelClosedException;
use App\Ipc\SocketChannel;
use App\Memory\ByteFormatter;
use App\Memory\MemoryReporter;

return new Experiment(
    name: 'ipc:backpressure',
    description: 'a producer faster than its consumer, and a producer killed mid-run',
    supports: ['payload-size', 'sleep'],
    run: static function (Options $options, Output $out): void {
        $out->heading('backpressure: a producer faster than its consumer');

        /*
         * A socket has a queue whether or not anyone designed one: the kernel send
         * and receive buffers. A producer runs at full speed until both are full,
         * and from then on it runs at exactly the consumer's speed, because each
         * send() has to wait for the consumer to make room. That wait is
         * backpressure, and it is the reason an unbounded in-process queue in front
         * of a socket usually replaces a bounded problem with an unbounded one.
         */
        $messageSize = $options->payloadSize(64 * 1024);
        $messages = 40;
        $consumerDelayMs = \intdiv($options->sleep(20_000), 1_000);

        $reporter = new MemoryReporter();
        [$producer, $consumer] = SocketChannel::pair();

        $pid = \pcntl_fork();

        if ($pid === 0) {
            $producer->close();

            for ($i = 0; $i < $messages; $i++) {
                \usleep($consumerDelayMs * 1000);
                $consumer->receive();
            }

            $consumer->close();

            exit(0);
        }

        $consumer->close();

        $out->write(\sprintf(
            "\nProducer sends %d x %s as fast as it can; the consumer sleeps %d ms per message.\n",
            $messages,
            ByteFormatter::format($messageSize),
            $consumerDelayMs,
        ));
        $out->write(\sprintf("Kernel send buffer: %s\n\n", ByteFormatter::format($producer->sendBufferSize())));

        $payload = \str_repeat('x', $messageSize);
        $before = $reporter->snapshot();
        $blockedNs = 0;
        $acceptedImmediately = 0;
        $stillFree = true;
        $start = \hrtime(true);

        for ($i = 0; $i < $messages; $i++) {
            $sendStart = \hrtime(true);
            $producer->send($payload);
            $elapsed = \hrtime(true) - $sendStart;

            // A send that returns in microseconds went into a buffer with room left;
            // one that takes milliseconds waited for the consumer to drain it.
            if ($elapsed < 1_000_000) {
                if ($stillFree) {
                    ++$acceptedImmediately;
                }
            } else {
                $stillFree = false;
                $blockedNs += $elapsed;
            }
        }

        $totalNs = \hrtime(true) - $start;

        $out->write(\sprintf(
            "Accepted without waiting: %d messages (%s) - the buffers absorbed that much before the producer felt anything.\n",
            $acceptedImmediately,
            ByteFormatter::format($acceptedImmediately * $messageSize),
        ));
        $out->write(\sprintf(
            "Blocked inside send():   %.1f ms of %.1f ms total (%.0f%% of the producer's life).\n",
            $blockedNs / 1e6,
            $totalNs / 1e6,
            100 * $blockedNs / $totalNs,
        ));
        $out->write(\sprintf(
            "Effective rate:          %.1f messages/s, against a consumer capable of %.1f/s.\n",
            $messages / ($totalNs / 1e9),
            1000 / $consumerDelayMs,
        ));

        $out->delta('producer, across the whole blocked run', $reporter->diff($before, $reporter->snapshot()));
        $out->note('the producer barely grows while blocked: a message waiting in the kernel buffer is charged to the kernel, not to this process. An in-process queue in front of the socket would have shown all 40 messages as PHP memory instead.');

        $producer->close();
        \pcntl_waitpid($pid, $status);

        /*
         * The second question: what happens to messages already sent when the
         * producer dies? They are not in the producer any more - they are in the
         * kernel, which does not care that the process that wrote them is gone.
         */
        [$reader, $writer] = SocketChannel::pair();

        $pid = \pcntl_fork();

        if ($pid === 0) {
            $reader->close();

            for ($i = 1; $i <= 5; $i++) {
                $writer->send('message ' . $i);
            }

            // SIGKILL, not exit(): no destructors, no flush, no chance to close the
            // socket politely. The harshest death a process can have.
            \posix_kill(\posix_getpid(), SIGKILL);
        }

        $writer->close();

        $received = [];

        try {
            while (true) {
                $received[] = $reader->receive();
            }
        } catch (ChannelClosedException $e) {
            $ending = $e->getMessage();
        }

        $reader->close();
        \pcntl_waitpid($pid, $status);

        $out->write(\sprintf(
            "\nProducer SIGKILLed after 5 sends: consumer read %d of them, then saw \"%s\".\n",
            \count($received),
            $ending,
        ));

        $out->note('nothing sent was lost: once send() returns, the bytes belong to the kernel buffer, which outlives the process that wrote them. Only the EOF changes - the consumer learns the peer is gone, not that data went missing.');
        $out->note('the same is not true of shared memory, where a producer killed mid-write leaves a half-written record behind (phase 6).');
        $out->context();
    },
);
