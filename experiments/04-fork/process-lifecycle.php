#!/usr/bin/env php
<?php

declare(strict_types=1);

require \dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../run-helpers.php';

experiment_start('process lifecycle: exit statuses, zombies, signals');

$waitFor = static function (int $pid, string $phase = ''): int {
    $waited = \pcntl_waitpid($pid, $status);
    $suffix = $phase !== '' ? " ($phase)" : '';

    if (\pcntl_wifsignaled($status)) {
        \fwrite(STDOUT, \sprintf(
            "  waitpid -> %d%s, terminated by signal %d\n",
            $waited,
            $suffix,
            \pcntl_wtermsig($status),
        ));

        return $status;
    }

    \fwrite(STDOUT, \sprintf(
        "  waitpid -> %d%s, exit status %d\n",
        $waited,
        $suffix,
        \pcntl_wexitstatus($status),
    ));

    return $status;
};

/*
 * 1. Normal exit: exit(0) -> wexitstatus 0.
 */
\fwrite(STDOUT, "1) normal exit (exit(0)):\n");
$pid = \pcntl_fork();
if ($pid === 0) {
    exit(0);
}
$waitFor($pid);

/*
 * 2. Non-zero exit: exit(3) -> wexitstatus 3.
 */
\fwrite(STDOUT, "\n2) non-zero exit (exit(3)):\n");
$pid = \pcntl_fork();
if ($pid === 0) {
    exit(3);
}
$waitFor($pid);

/*
 * 3. Zombie: the child exits but the parent delays waitpid; the state
 *    visible in /proc/<pid>/status is Z until the parent reaps it.
 */
\fwrite(STDOUT, "\n3) zombie: child exits, parent delays waitpid:\n");
$pid = \pcntl_fork();
if ($pid === 0) {
    exit(0);
}

\sleep(1);
$stateLine = '';
foreach (\file('/proc/' . $pid . '/status', \FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (\str_starts_with($line, 'State:')) {
        $stateLine = \trim($line);
        break;
    }
}
\fwrite(STDOUT, \sprintf("  child still unreaped, /proc/%d/status: %s\n", $pid, $stateLine));
$waitFor($pid, 'reaped after the delay');

/*
 * 4. Signal termination: the child blocks; the parent sends SIGTERM and the
 *    wait shows wifsignaled + wtermsig.
 */
\fwrite(STDOUT, "\n4) killed by signal (SIGTERM):\n");
$pid = \pcntl_fork();
if ($pid === 0) {
    \sleep(30);
    exit(0);
}

\usleep(200_000);
\posix_kill($pid, \SIGTERM);
$waitFor($pid, 'after SIGTERM');

experiment_note('an unreaped child is a zombie (Z) that holds its pid + exit state but no memory working set; a long-running supervisor must waitpid() to reap.');
experiment_context();