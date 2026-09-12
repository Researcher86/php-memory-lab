#!/usr/bin/env php
<?php

declare(strict_types=1);

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Memory\ByteFormatter;
use App\Memory\MemoryDiff;
use App\Memory\MemoryReporter;
use App\Memory\MemorySnapshot;

$reporter = new MemoryReporter();

\fwrite(STDOUT, "Experiment: empty-process baseline (Phase 1)\n");
\fwrite(STDOUT, \sprintf("PID: %d\n", \getmypid()));

$before = $reporter->snapshot();
\printSnapshot('Before', $before);

$bucket = \range(1, 1_000_000);
$afterAllocation = $reporter->snapshot();
\printSnapshot('After allocation', $afterAllocation);
\printDiff('Allocation', $reporter->diff($before, $afterAllocation));

unset($bucket);
$afterCleanup = $reporter->snapshot();
\printSnapshot('After cleanup', $afterCleanup);
\printDiff('Cleanup', $reporter->diff($afterAllocation, $afterCleanup));

\fwrite(STDOUT, "\nContext: values depend on the PHP version, allocator, and container limits.\n");

function printSnapshot(string $label, MemorySnapshot $snapshot): void
{
    \fwrite(STDOUT, \sprintf("\n%s:\n", $label));
    \fwrite(STDOUT, \sprintf(
        "  PHP usage: %s\n",
        ByteFormatter::format($snapshot->phpUsage),
    ));
    \fwrite(STDOUT, \sprintf(
        "  RSS:       %s\n",
        $snapshot->rss === null ? 'n/a' : ByteFormatter::format($snapshot->rss),
    ));
    \fwrite(STDOUT, \sprintf(
        "  Private:   %s\n",
        $snapshot->privateMemory === null ? 'n/a' : ByteFormatter::format($snapshot->privateMemory),
    ));
}

function printDiff(string $label, MemoryDiff $diff): void
{
    \fwrite(STDOUT, \sprintf("\nDelta %s:\n", $label));
    \fwrite(STDOUT, \sprintf(
        "  PHP usage: %s\n",
        ByteFormatter::formatSigned($diff->phpUsage),
    ));
    \fwrite(STDOUT, \sprintf(
        "  RSS:       %s\n",
        $diff->rss === null ? 'n/a' : ByteFormatter::formatSigned($diff->rss),
    ));
    \fwrite(STDOUT, \sprintf(
        "  Private:   %s\n",
        $diff->privateMemory === null ? 'n/a' : ByteFormatter::formatSigned($diff->privateMemory),
    ));
}