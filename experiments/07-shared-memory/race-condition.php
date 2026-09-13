#!/usr/bin/env php
<?php

declare(strict_types=1);

require \dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../run-helpers.php';

use App\Ipc\Exception\SharedMemoryException;
use App\Ipc\Semaphore;
use App\Ipc\SharedMemorySegment;

experiment_start('shared memory without a lock: counting how many updates get lost');

/*
 * One counter in shared memory, N children, M increments each. The expected
 * total is N * M and arithmetic has nothing to do with why it is not reached.
 *
 * `$counter = $counter + 1` across processes is three steps - read, add,
 * write - and the value read is only still correct if nobody wrote between
 * the read and the write. Every process that does write in that window has
 * its increment overwritten by the next writer, who is adding 1 to a number
 * that is already stale. Nothing errors; the total is simply short.
 *
 * PHP's serialization makes the window wide: get() deserializes and put()
 * serializes, so the two steps are microseconds apart rather than one CPU
 * instruction. A C program doing the same thing loses fewer updates - and
 * still loses them.
 */
$increments = 500;
$counterIndex = 1;

/**
 * @return array{total: int, survived: bool, elapsedMs: float}
 */
function count_together(int $children, int $increments, int $counterIndex, bool $synchronized): array
{
    $segmentKey = SharedMemorySegment::randomKey();
    $segment = SharedMemorySegment::attach($segmentKey);
    $semaphore = Semaphore::attach(SharedMemorySegment::randomKey());
    $segment->put($counterIndex, 0);

    $start = \hrtime(true);
    $pids = [];

    for ($c = 0; $c < $children; $c++) {
        $pid = \pcntl_fork();

        if ($pid === 0) {
            $ownSegment = SharedMemorySegment::attach($segmentKey);
            $ownSemaphore = Semaphore::attach($semaphore->key);

            $increment = static function () use ($ownSegment, $counterIndex): void {
                try {
                    // The read-modify-write. Three operations, two windows.
                    $current = (int) $ownSegment->get($counterIndex);
                    $ownSegment->put($counterIndex, $current + 1);
                } catch (SharedMemoryException) {
                    // Unsynchronized only, and worse than a lost update:
                    // shm_put_var() replaces a variable by removing it and
                    // inserting it again, so for a moment the counter does
                    // not exist at all. A reader landing in that window finds
                    // nothing to read. The increment is dropped and the loop
                    // continues, which is the most a process can do about a
                    // race it cannot see.
                }
            };

            for ($i = 0; $i < $increments; $i++) {
                $synchronized ? $ownSemaphore->synchronized($increment) : $increment();
            }

            $ownSegment->detach();

            exit(0);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        \pcntl_waitpid($pid, $status);
    }

    $elapsedMs = (\hrtime(true) - $start) / 1e6;
    $survived = $segment->has($counterIndex);
    $total = $survived ? (int) $segment->get($counterIndex) : 0;

    $segment->destroy();
    $semaphore->remove();

    return ['total' => $total, 'survived' => $survived, 'elapsedMs' => $elapsedMs];
}

\fwrite(STDOUT, \sprintf(
    "\nEvery run: %d increments per child, expected total = children x %d.\n\n",
    $increments,
    $increments,
));
\fwrite(STDOUT, \sprintf(
    "%8s | %8s | %19s | %19s\n",
    'children',
    'expected',
    'unsynchronized',
    'semaphore-protected',
));
\fwrite(STDOUT, \str_repeat('-', 64) . "\n");

foreach ([1, 2, 4, 8, 16] as $children) {
    $expected = $children * $increments;
    $raced = count_together($children, $increments, $counterIndex, false);
    $locked = count_together($children, $increments, $counterIndex, true);

    \fwrite(STDOUT, \sprintf(
        "%8d | %8d | %19s | %6d (%6.1f ms)\n",
        $children,
        $expected,
        $raced['survived']
            ? \sprintf('%6d (%5.1f%% lost)', $raced['total'], 100 * ($expected - $raced['total']) / $expected)
            : '  counter destroyed',
        $locked['total'],
        $locked['elapsedMs'],
    ));
}

experiment_note('with one child there is nobody to race against, so the unsynchronized column is correct - which is exactly how this class of bug survives testing and shows up under load.');
experiment_note('a few writers lose updates silently: no error, no warning, just a total that does not add up. That is the failure mode a retry cannot fix, because nothing reports a failure to retry.');
experiment_note('enough writers and the counter stops existing. PHP stores each variable in a directory inside the segment and rewrites the entry in place; concurrent writers corrupt that bookkeeping, not just the value. Shared memory does not degrade gracefully - it stops being a data structure.');
experiment_note('the lock costs wall-clock time, growing roughly linearly with the number of writers, and buys the only thing that matters: the protected column always equals the expected one.');
experiment_context();
