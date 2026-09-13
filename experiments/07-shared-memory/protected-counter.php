#!/usr/bin/env php
<?php

declare(strict_types=1);

require \dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../run-helpers.php';

use App\Ipc\Semaphore;
use App\Ipc\SharedMemorySegment;

experiment_start('a shared counter that is always right, and what the lock costs');

/*
 * The same counter as race-condition.php, this time correct: every
 * read-modify-write happens while holding the semaphore, so no two processes
 * are ever inside it at once. The interesting part is no longer the total -
 * it is exact by construction - but the price, which is that the children
 * spend most of their lives waiting for each other.
 */
$children = 4;
$increments = 1_000;
$counterIndex = 1;
$reportBase = 100;

$segment = SharedMemorySegment::attach(SharedMemorySegment::randomKey());
$semaphore = Semaphore::attach(SharedMemorySegment::randomKey());
$segment->put($counterIndex, 0);

$start = \hrtime(true);
$pids = [];

for ($c = 0; $c < $children; $c++) {
    $pid = \pcntl_fork();

    if ($pid === 0) {
        $ownSegment = SharedMemorySegment::attach($segment->key);
        $ownSemaphore = Semaphore::attach($semaphore->key);
        $waitedNs = 0;
        $childStart = \hrtime(true);

        for ($i = 0; $i < $increments; $i++) {
            // acquire() is timed separately from the critical section: the
            // wait is contention, the section is work, and telling them apart
            // is the whole question when a lock looks expensive.
            $waitStart = \hrtime(true);
            $ownSemaphore->acquire();
            $waitedNs += \hrtime(true) - $waitStart;

            try {
                $ownSegment->put($counterIndex, (int) $ownSegment->get($counterIndex) + 1);
            } finally {
                $ownSemaphore->release();
            }
        }

        $elapsedNs = \hrtime(true) - $childStart;

        // Reporting back through the same shared memory - and under the same
        // lock, because the rule does not stop applying for one small write.
        $ownSemaphore->synchronized(static function () use ($ownSegment, $reportBase, $c, $waitedNs, $elapsedNs): void {
            $ownSegment->put($reportBase + $c, ['waitedNs' => $waitedNs, 'elapsedNs' => $elapsedNs]);
        });

        $ownSegment->detach();

        exit(0);
    }

    $pids[] = $pid;
}

foreach ($pids as $pid) {
    \pcntl_waitpid($pid, $status);
}

$elapsedMs = (\hrtime(true) - $start) / 1e6;
$total = (int) $segment->get($counterIndex);
$expected = $children * $increments;

\fwrite(STDOUT, \sprintf(
    "\n%d children x %d increments, every one of them under the semaphore.\n",
    $children,
    $increments,
));
\fwrite(STDOUT, \sprintf(
    "Counter: %d, expected %d - %s\n",
    $total,
    $expected,
    $total === $expected ? 'exact' : 'WRONG, the lock did not hold',
));
\fwrite(STDOUT, \sprintf(
    "Wall clock: %.1f ms for %d protected updates (%.0f updates/s).\n\n",
    $elapsedMs,
    $expected,
    $expected / ($elapsedMs / 1000),
));

\fwrite(STDOUT, \sprintf("%6s | %12s | %12s | %s\n", 'child', 'total', 'waiting', 'share spent waiting'));
\fwrite(STDOUT, \str_repeat('-', 56) . "\n");

for ($c = 0; $c < $children; $c++) {
    /** @var array{waitedNs: int, elapsedNs: int} $report */
    $report = $segment->get($reportBase + $c);

    \fwrite(STDOUT, \sprintf(
        "%6d | %9.1f ms | %9.1f ms | %4.1f%%\n",
        $c,
        $report['elapsedNs'] / 1e6,
        $report['waitedNs'] / 1e6,
        100 * $report['waitedNs'] / $report['elapsedNs'],
    ));
}

experiment_note('a lock turns concurrent processes into a queue. Four children do not finish four times faster - they take turns, and the time they spend waiting is time the CPU has nothing to do on their behalf.');

$segment->destroy();
$semaphore->remove();

/*
 * The other half of correctness: what happens to a lock whose holder dies.
 * A SysV semaphore is held in the kernel, and PHP passes SEM_UNDO on every
 * acquire, so the kernel knows to undo the acquisition when the process
 * behind it disappears. A lock written into shared memory by hand is just a
 * value, and nobody undoes a value.
 */
$acquirableWithin = static function (Semaphore $semaphore, float $seconds): bool {
    $deadline = \microtime(true) + $seconds;

    while (\microtime(true) < $deadline) {
        if ($semaphore->tryAcquire()) {
            $semaphore->release();

            return true;
        }

        \usleep(10_000);
    }

    return false;
};

$kernelLock = Semaphore::attach(SharedMemorySegment::randomKey());
$handMade = SharedMemorySegment::attach(SharedMemorySegment::randomKey());
$handMade->put(1, 'free');

$pid = \pcntl_fork();

if ($pid === 0) {
    Semaphore::attach($kernelLock->key)->acquire();
    SharedMemorySegment::attach($handMade->key)->put(1, 'held by ' . \posix_getpid());

    // SIGKILL while holding both: no shutdown, no destructors, no unlock.
    \posix_kill(\posix_getpid(), SIGKILL);
}

\pcntl_waitpid($pid, $status);

\fwrite(STDOUT, \sprintf(
    "\nHolder SIGKILLed while holding two locks at once:\n  SysV semaphore:       %s\n  flag in shared memory: %s\n",
    $acquirableWithin($kernelLock, 1.0)
        ? 'acquirable again - the kernel undid the acquisition'
        : 'STILL HELD',
    $handMade->get(1) === 'free'
        ? 'free again - impossible, nothing could have reset it'
        : \sprintf('still says "%s" - every waiter would wait forever', $handMade->get(1)),
));

$kernelLock->remove();
$handMade->destroy();

experiment_note('SEM_UNDO is the difference between a crash that costs one request and a crash that wedges every process sharing the lock. It is also the reason to use the semaphore the kernel offers rather than a flag in the segment: a hand-made lock has no owner the kernel knows about, so nothing unwinds it when that owner stops existing.');
experiment_note('the $autoRelease argument to sem_get() is a weaker, separate thing - it releases at PHP\'s request shutdown, which in CLI is process exit anyway. Process death is covered by SEM_UNDO either way.');
experiment_context();
