<?php

declare(strict_types=1);

namespace App\Memory;

/**
 * The delta between two MemorySnapshots. PHP counters are always ints; OS
 * fields stay null when either side was missing /proc data.
 */
final readonly class MemoryDiff
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
    ) {}
}
