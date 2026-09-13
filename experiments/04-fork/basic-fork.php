#!/usr/bin/env php
<?php

declare(strict_types=1);

require \dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../run-helpers.php';

use App\Memory\ByteFormatter;

experiment_start('fork: parent and child measured independently');

$reporter = experiment_reporter();

$before = $reporter->snapshot();

$start = \microtime(true);
$pid = \pcntl_fork();
$forkTimeMs = (\microtime(true) - $start) * 1000;

if ($pid === -1) {
    \fwrite(STDERR, "fork() failed\n");
    exit(1);
}

if ($pid === 0) {
    $child = $reporter->snapshot();
    \fwrite(STDOUT, \sprintf("\n--- child (pid %d, parent %d) ---\n", \getmypid(), \posix_getppid()));
    \fwrite(STDOUT, \sprintf("  PHP usage: %s\n", ByteFormatter::format($child->phpUsage)));
    \fwrite(STDOUT, \sprintf("  RSS:       %s\n", ByteFormatter::format($child->rss ?? 0)));
    \fwrite(STDOUT, \sprintf("  PSS:       %s\n", ByteFormatter::format($child->pss ?? 0)));
    \fwrite(STDOUT, \sprintf("  VmSize:    %s\n", ByteFormatter::format($child->virtualMemory ?? 0)));
    exit(0);
}

$waited = \pcntl_waitpid($pid, $status);

\fwrite(STDOUT, \sprintf(
    "\nparent waitpid(%d) returned %d, raw status %d, exit status %d\n",
    $pid,
    $waited,
    $status,
    \pcntl_wexitstatus($status),
));

$after = $reporter->snapshot();
\fwrite(STDOUT, \sprintf("fork() took %.2f ms\n", $forkTimeMs));
experiment_delta('parent after waiting for the child', $reporter->diff($before, $after));

experiment_note('parent and child each ran their own snapshot; identical VmSize (the child inherits the address space), independent PID.');
experiment_context();