<?php

declare(strict_types=1);

namespace App\Memory;

use RuntimeException;

/**
 * Reads /proc/<pid>/smaps_rollup - the kernel's per-process summary of every
 * memory mapping. Fields that the current kernel does not report stay at
 * their DTO default (0) rather than blowing up the parse.
 */
final class SmapsRollupReader
{
    public function read(int $pid = 0): SmapsRollup
    {
        $actualPid = $pid > 0 ? $pid : getmypid();

        if ($actualPid === false) {
            throw new RuntimeException('Unable to determine process ID');
        }

        $path = \sprintf('/proc/%d/smaps_rollup', $actualPid);

        if (!is_readable($path)) {
            throw new RuntimeException(\sprintf('Unable to read %s', $path));
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException(\sprintf('Unable to read %s', $path));
        }

        return $this->parse($content);
    }

    public function parse(string $content): SmapsRollup
    {
        $values = [];

        foreach (explode("\n", $content) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s+kB$/', $line, $matches) === 1) {
                $values[$matches[1]] = (int) $matches[2] * 1024;
            }
        }

        $extract = static fn (string $key): int => $values[$key] ?? 0;

        return new SmapsRollup(
            rss: $extract('Rss'),
            pss: $extract('Pss'),
            pssAnon: $extract('Pss_Anon'),
            pssFile: $extract('Pss_File'),
            pssShmem: $extract('Pss_Shmem'),
            sharedClean: $extract('Shared_Clean'),
            sharedDirty: $extract('Shared_Dirty'),
            privateClean: $extract('Private_Clean'),
            privateDirty: $extract('Private_Dirty'),
            anonymous: $extract('Anonymous'),
            anonHugePages: $extract('AnonHugePages'),
            swap: $extract('Swap'),
        );
    }
}
