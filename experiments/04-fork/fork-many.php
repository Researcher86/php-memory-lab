#!/usr/bin/env php
<?php

declare(strict_types=1);

require \dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../run-helpers.php';

use App\Memory\ByteFormatter;
use App\Memory\ProcStatusReader;

experiment_start('fork: one / two / four / eight / sixteen children');

/*
 * Each child stays alive (sleep) while the parent snapshots itself and reads
 * every child's VmRSS from /proc/<pid>/status. RSS is counted per process, so
 * shared pages appear in several rows at once; PSS divides them.
 */
$reporter = experiment_reporter();
$statusReader = new ProcStatusReader();

$forkChild = static function () {
    \usleep(800_000);
    exit(0);
};

foreach ([1, 2, 4, 8, 16] as $childrenCount) {
    $baseline = $reporter->snapshot();
    $start = \microtime(true);

    $children = [];
    for ($i = 0; $i < $childrenCount; ++$i) {
        $pid = \pcntl_fork();
        if ($pid === -1) {
            \fwrite(STDERR, "fork() failed\n");
            exit(1);
        }
        if ($pid === 0) {
            $forkChild();
        }
        $children[] = $pid;
    }

    $forkTimeMs = (\microtime(true) - $start) * 1000;

    \usleep(150_000); // let the children run their tiny sleep
    $during = $reporter->snapshot();

    $childrenRss = 0;
    foreach ($children as $pid) {
        $status = $statusReader->read($pid);
        $childrenRss += (int) $status['VmRSS'];
    }

    $totalRss = (int) $during->rss + $childrenRss;

    \fwrite(STDOUT, \sprintf(
        "children %2d | forks %.2f ms (%.2f ms/fork) | parent RSS %-9s | parent PSS %-9s | children RSS %-9s | approx total RSS %s\n",
        $childrenCount,
        $forkTimeMs,
        $forkTimeMs / $childrenCount,
        ByteFormatter::format((int) $during->rss),
        ByteFormatter::format((int) $during->pss),
        ByteFormatter::format($childrenRss),
        ByteFormatter::format($totalRss),
    ));

    foreach ($children as $pid) {
        \pcntl_waitpid($pid, $status);
    }
}

\fwrite(STDOUT, "\n");
experiment_note('"approx total RSS" sums per-process RSS, so it double-counts the pages the children share with the parent; PSS is the honest total.');
experiment_context();