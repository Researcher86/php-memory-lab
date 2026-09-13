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
    name: 'ipc:socket',
    description: 'socket round-trip latency and throughput, 0 B to 10 MiB',
    supports: ['iterations'],
    run: static function (Options $options, Output $out): void {
        $out->heading('unix socket round-trip: latency and throughput by payload size');

        /*
         * One parent, one forked echo child, one socket pair between them. Every
         * round trip is four copies through the kernel - parent to buffer, buffer to
         * child, and the same back - so the numbers below answer two questions at
         * once: what does a message cost when the payload is negligible (syscalls and
         * scheduling), and what does it cost when the payload dominates (memory
         * bandwidth through the socket buffer).
         */
        // --iterations scales every row rather than replacing it: a 10 MiB
        // round trip and an empty one need counts three orders of magnitude
        // apart to be equally measurable, so the ratios are the interesting
        // part and the scale is the knob.
        $scale = $options->iterations(2_000) / 2_000;
        $sizes = [
            '0 B' => [0, (int) \max(1, 2_000 * $scale)],
            '64 B' => [64, (int) \max(1, 2_000 * $scale)],
            '1 KiB' => [1024, (int) \max(1, 2_000 * $scale)],
            '64 KiB' => [64 * 1024, (int) \max(1, 500 * $scale)],
            '1 MiB' => [1024 * 1024, (int) \max(1, 50 * $scale)],
            '10 MiB' => [10 * 1024 * 1024, (int) \max(1, 10 * $scale)],
        ];

        $reporter = new MemoryReporter();
        [$parent, $child] = SocketChannel::pair();

        $pid = \pcntl_fork();

        if ($pid === 0) {
            // The child owns exactly one end. Keeping the parent's copy open here
            // would mean the parent's end never reaches a refcount of zero, so the
            // child's reads could never see EOF and this loop would never end.
            $parent->close();

            try {
                while (true) {
                    $child->send($child->receive());
                }
            } catch (ChannelClosedException) {
                // The parent finished and closed its end; nothing left to echo.
            }

            $child->close();

            exit(0);
        }

        $child->close();

        $before = $reporter->snapshot();

        $out->write(\sprintf(
            "\n%-8s | %6s | %10s %10s %10s | %12s\n",
            'payload',
            'trips',
            'mean',
            'min',
            'max',
            'throughput',
        ));
        $out->write(\str_repeat('-', 68) . "\n");

        foreach ($sizes as $label => [$size, $iterations]) {
            $payload = \str_repeat('x', $size);

            // A few untimed trips first: the kernel grows the socket buffers on
            // demand, and the first message of a new size pays for that growth.
            for ($i = 0; $i < 3; $i++) {
                $parent->send($payload);
                $parent->receive();
            }

            $min = \PHP_INT_MAX;
            $max = 0;
            $total = 0;

            for ($i = 0; $i < $iterations; $i++) {
                $start = \hrtime(true);
                $parent->send($payload);
                $echo = $parent->receive();
                $elapsed = \hrtime(true) - $start;

                if (\strlen($echo) !== $size) {
                    throw new RuntimeException(\sprintf('echo returned %d bytes, expected %d', \strlen($echo), $size));
                }

                $total += $elapsed;
                $min = \min($min, $elapsed);
                $max = \max($max, $elapsed);
            }

            // Both directions carry the payload, so a round trip moves 2x its size.
            $bytesPerSecond = $total === 0 ? 0 : (int) (2 * $size * $iterations / ($total / 1e9));

            $out->write(\sprintf(
                "%-8s | %6d | %8.3f ms %8.3f ms %8.3f ms | %9s/s\n",
                $label,
                $iterations,
                $total / $iterations / 1e6,
                $min / 1e6,
                $max / 1e6,
                ByteFormatter::format($bytesPerSecond),
            ));
        }

        $out->delta('parent, across every measured round trip', $reporter->diff($before, $reporter->snapshot()));
        $out->note('that delta is the last payload and its echo still in scope, not channel overhead: the reassembly buffer is empty between messages, because every frame is handed out whole as soon as it completes.');

        /*
         * The same exchange without blocking. receiveOrNull() returns null while the
         * reply is still in flight, so the count below is how many times this process
         * asked before the answer existed - the price of not blocking is that the
         * waiting has to happen somewhere, and here it happens in user space.
         */
        $polls = 0;
        $parent->send(\str_repeat('x', 1024));

        while ($parent->receiveOrNull() === null) {
            ++$polls;
        }

        $out->note(\sprintf(
            'non-blocking: receiveOrNull() returned null %d times before the 1 KiB reply landed; receive() spends that same wait inside one recv() syscall instead.',
            $polls,
        ));

        $out->write(\sprintf("Kernel send buffer on this channel: %s\n", ByteFormatter::format($parent->sendBufferSize())));

        $parent->close();
        \pcntl_waitpid($pid, $status);

        $out->note('small payloads measure syscall and scheduling cost, large ones measure copies through the socket buffer; the crossover is where throughput stops improving.');
        $out->context();
    },
);
