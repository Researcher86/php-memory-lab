<?php

declare(strict_types=1);

use App\Memory\ByteFormatter;
use App\Memory\MemoryDiff;
use App\Memory\MemoryReporter;
use App\Memory\MemorySnapshot;
use App\Memory\SmapsRollup;

/**
 * Shared output helpers for experiment scripts.
 *
 * These files live outside src/ on purpose: experiments are demo scripts,
 * not library code, so they skip PHPStan and PHP-CS-Fixer. Every experiment
 * prints through these so the numbers stay comparable between runs.
 */

function experiment_start(string $title): void
{
    \fwrite(STDOUT, \sprintf("Experiment: %s\n", $title));
    \fwrite(STDOUT, \sprintf("PID: %d\n", \getmypid()));
}

function experiment_reporter(): MemoryReporter
{
    return new MemoryReporter();
}

function experiment_snapshot(string $label, MemorySnapshot $snapshot): void
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

function experiment_delta(string $label, MemoryDiff $diff): void
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

function experiment_note(string $message): void
{
    \fwrite(STDOUT, \sprintf("\nNote: %s\n", $message));
}

function experiment_smaps(string $label, SmapsRollup $rollup): void
{
    \fwrite(STDOUT, \sprintf(
        "%s: RSS %s | PSS %s | Shared_Dirty %s | Private_Dirty %s\n",
        $label,
        ByteFormatter::format($rollup->rss),
        ByteFormatter::format($rollup->pss),
        ByteFormatter::format($rollup->sharedDirty),
        ByteFormatter::format($rollup->privateDirty),
    ));
}

function experiment_context(): void
{
    \fwrite(STDOUT, "\nContext: values depend on the PHP version, allocator, and container limits.\n");
}