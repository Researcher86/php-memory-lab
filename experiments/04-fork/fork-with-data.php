<?php

declare(strict_types=1);

use App\Experiment\Experiment;
use App\Experiment\Options;
use App\Experiment\Output;
use App\Memory\ByteFormatter;
use App\Memory\MemoryReporter;

return new Experiment(
    name: 'process:fork-with-data',
    description: 'a large array inherited read-only through fork()',
    supports: ['elements'],
    run: static function (Options $options, Output $out): void {
        $out->heading('fork: 1,000,000-integer array inherited by the child');

        $reporter = new MemoryReporter();
        $count = $options->elements(1_000_000);

        $before = $reporter->snapshot();
        $data = \range(1, $count);
        $afterAlloc = $reporter->snapshot();
        $out->delta('parent allocated', $reporter->diff($before, $afterAlloc));

        $pid = \pcntl_fork();

        if ($pid === -1) {
            \fwrite(STDERR, "fork() failed\n");
            exit(1);
        }

        if ($pid === 0) {
            // The child only reads: no page is copied, Linux CoW holds it shared.
            \usleep(150_000);
            $child = $reporter->snapshot();
            $out->write("\n--- child after fork (read-only on the inherited array) ---\n");
            $out->write(\sprintf("  PHP usage: %s\n", ByteFormatter::format($child->phpUsage)));
            $out->write(\sprintf("  RSS:       %s\n", ByteFormatter::format($child->rss ?? 0)));
            $out->write(\sprintf("  PSS:       %s\n", ByteFormatter::format($child->pss ?? 0)));
            $out->write(\sprintf(
                "  read check (first + last element): %d\n",
                $data[0] + $data[$count - 1],
            ));
            exit(0);
        }

        // Parent a moment later: the child shares the array's pages, so the parent's
        // own RSS does not double.
        \usleep(100_000);
        $during = $reporter->snapshot();
        $out->write("\nparent while child lives (read-only child):\n");
        $out->write(\sprintf("  RSS: %s    PSS: %s\n", ByteFormatter::format($during->rss ?? 0), ByteFormatter::format($during->pss ?? 0)));

        \pcntl_waitpid($pid, $status);

        $after = $reporter->snapshot();
        $out->delta('parent after the child exited', $reporter->diff($afterAlloc, $after));

        $out->note('the child inherited the array without a copy; child RSS mirrors the parent while PSS shows how little the child actually owns.');
        $out->context();
    },
);
