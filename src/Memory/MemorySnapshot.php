<?php

declare(strict_types=1);

namespace App\Memory;

/**
 * One point-in-time measurement of the process, combining PHP-allocator
 * counters with Linux /proc metrics. The nullable OS fields stay null when
 * /proc is unavailable, so a snapshot still works on a non-Linux host for the
 * PHP-only fields.
 */
final readonly class MemorySnapshot
{
    public function __construct(
        public int $phpUsage,
        public int $phpPeakUsage,
        public int $phpRealUsage,
        public int $phpRealPeakUsage,
        public ?int $rss = null,
        public ?int $virtualMemory = null,
        public ?int $sharedMemory = null,
        public ?int $privateMemory = null,
        public ?int $pss = null,
    ) {
    }
}
