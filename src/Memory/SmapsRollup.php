<?php

declare(strict_types=1);

namespace App\Memory;

/**
 * The interesting subset of /proc/<pid>/smaps_rollup. Unlike Rss, Pss and the
 * Shared_* / Private_* split attribute every page to whichever process is
 * asking, so these are the numbers that a Copy-on-Write experiment needs: a
 * write that privatises a shared page shows up as Shared_Dirty falling and
 * Private_Dirty rising while Rss does not move.
 */
final readonly class SmapsRollup
{
    public function __construct(
        public int $rss = 0,
        public int $pss = 0,
        public int $pssAnon = 0,
        public int $pssFile = 0,
        public int $pssShmem = 0,
        public int $sharedClean = 0,
        public int $sharedDirty = 0,
        public int $privateClean = 0,
        public int $privateDirty = 0,
        public int $anonymous = 0,
        public int $anonHugePages = 0,
        public int $swap = 0,
    ) {}
}
