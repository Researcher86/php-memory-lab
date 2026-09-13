#!/usr/bin/env php
<?php

declare(strict_types=1);

require \dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../run-helpers.php';

use App\Memory\ByteFormatter;

experiment_start('fork: 1,000,000-integer array inherited by the child');

$reporter = experiment_reporter();
$count = 1_000_000;

$before = $reporter->snapshot();
$data = \range(1, $count);
$afterAlloc = $reporter->snapshot();
experiment_delta('parent allocated', $reporter->diff($before, $afterAlloc));

$pid = \pcntl_fork();

if ($pid === -1) {
    \fwrite(STDERR, "fork() failed\n");
    exit(1);
}

if ($pid === 0) {
    // The child only reads: no page is copied, Linux CoW holds it shared.
    \usleep(150_000);
    $child = $reporter->snapshot();
    \fwrite(STDOUT, "\n--- child after fork (read-only on the inherited array) ---\n");
    \fwrite(STDOUT, \sprintf("  PHP usage: %s\n", ByteFormatter::format($child->phpUsage)));
    \fwrite(STDOUT, \sprintf("  RSS:       %s\n", ByteFormatter::format($child->rss ?? 0)));
    \fwrite(STDOUT, \sprintf("  PSS:       %s\n", ByteFormatter::format($child->pss ?? 0)));
    \fwrite(STDOUT, \sprintf(
        "  read check (first + last element): %d\n",
        $data[0] + $data[$count - 1],
    ));
    exit(0);
}

// Parent a moment later: the child shares the array's pages, so the parent's
// own RSS does not double.
\usleep(100_000);
$during = $reporter->snapshot();
\fwrite(STDOUT, "\nparent while child lives (read-only child):\n");
\fwrite(STDOUT, \sprintf("  RSS: %s    PSS: %s\n", ByteFormatter::format($during->rss ?? 0), ByteFormatter::format($during->pss ?? 0)));

\pcntl_waitpid($pid, $status);

$after = $reporter->snapshot();
experiment_delta('parent after the child exited', $reporter->diff($afterAlloc, $after));

experiment_note('the child inherited the array without a copy; child RSS mirrors the parent while PSS shows how little the child actually owns.');
experiment_context();