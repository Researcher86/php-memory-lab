<?php

declare(strict_types=1);

namespace App\Memory;

use RuntimeException;

/**
 * Parses /proc/<pid>/status - a flat key: value list that mixes kB-backed
 * memory fields (VmRSS, RssAnon, ...) with plain integers (Threads) and
 * arbitrary strings (Name). Values that end in " kB" arrive as bytes, because
 * one unit everywhere is easier to reason about in diff reports than mixing
 * pages and bytes.
 */
final class ProcStatusReader
{
    /**
     * @param int $pid Process to inspect; 0 means the current process.
     *
     * @return array<string, int|string>
     *
     * @throws RuntimeException when the file is unreadable (non-Linux host,
     *                          unknown pid).
     */
    public function read(int $pid = 0): array
    {
        return $this->parse(ProcFile::read('status', $pid));
    }

    /**
     * @return array<string, int|string>
     */
    public function parse(string $content): array
    {
        $result = [];

        foreach (explode("\n", $content) as $line) {
            $colon = strpos($line, ':');

            if ($colon === false) {
                continue;
            }

            $result[substr($line, 0, $colon)] = $this->convertValue(trim(substr($line, $colon + 1)));
        }

        return $result;
    }

    private function convertValue(string $value): int|string
    {
        if (preg_match('/^(\d+)\s+kB$/', $value, $matches) === 1) {
            return (int) $matches[1] * 1024;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $value;
    }
}
