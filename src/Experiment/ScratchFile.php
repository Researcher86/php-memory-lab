<?php

declare(strict_types=1);

namespace App\Experiment;

/**
 * A throwaway path under the system temp directory, removed when the process
 * that reserved it ends.
 *
 * The mmap experiments each need a file to map and each used to delete it on
 * the last line, which is the one line an exception skips. A 256 MiB sparse
 * file then stays behind - `mmap:lazy` left exactly one during this project's
 * own development - and the name carries a random suffix, so interrupted runs
 * accumulate rather than overwrite. `docs/PHASES.md` has said "always clean up
 * temporary files" since Phase 0; this is the part that was missing.
 *
 * The shutdown function is a backstop and not the plan: an experiment still
 * deletes its file where the narrative wants it gone, exactly as `FfiBuffer`
 * keeps `free()` explicit and `__destruct()` as the net.
 *
 * The pid guard is what makes this safe to use in the forking experiments.
 * `register_shutdown_function()` is inherited across `pcntl_fork()`, so
 * without it a child calling `exit(0)` would delete a file its parent is
 * still mapping - which is `mmap:modes`, precisely.
 */
final readonly class ScratchFile
{
    private function __construct()
    {
    }

    /**
     * A path nothing else is using, scheduled for removal at the end of this
     * process. The file is not created - callers map it, truncate it or write
     * it themselves.
     *
     * @param string $prefix short name of the experiment, to make a stray file
     *                       identifiable if one ever does survive
     */
    public static function reserve(string $prefix): string
    {
        $path = sprintf('%s/%s-%s.bin', sys_get_temp_dir(), $prefix, bin2hex(random_bytes(4)));

        self::removeOnExit($path);

        return $path;
    }

    /**
     * Schedules a path for removal without reserving it, for the second file
     * an experiment derives from the first.
     */
    public static function removeOnExit(string $path): void
    {
        $owner = getmypid();

        register_shutdown_function(static function () use ($path, $owner): void {
            if (getmypid() === $owner) {
                @unlink($path);
            }
        });
    }
}
