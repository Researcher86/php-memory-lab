<?php

declare(strict_types=1);

namespace App\Memory;

use RuntimeException;

/**
 * Reads one file out of /proc for a process.
 *
 * Four lines of resolving a pid, building a path and checking a read - which
 * both readers in this namespace need and neither should own. The single
 * place also carries the one thing a caller has to know: /proc is Linux, and
 * on any other host every read from here fails. Callers above turn that into
 * null fields rather than an error, because a PHP-only measurement is still
 * a measurement.
 */
final class ProcFile
{
    private function __construct()
    {
    }

    /**
     * @param string $name file under /proc/<pid>/, e.g. "status"
     * @param int    $pid  process to inspect; 0 means this one
     *
     * @throws RuntimeException when the file cannot be read - a non-Linux
     *                          host, or a pid that has gone away
     */
    public static function read(string $name, int $pid = 0): string
    {
        $target = $pid > 0 ? $pid : getmypid();

        if ($target === false) {
            throw new RuntimeException('Unable to determine process ID');
        }

        $path = sprintf('/proc/%d/%s', $target, $name);

        if (!is_readable($path)) {
            throw new RuntimeException(sprintf('Unable to read %s', $path));
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException(sprintf('Unable to read %s', $path));
        }

        return $content;
    }
}
