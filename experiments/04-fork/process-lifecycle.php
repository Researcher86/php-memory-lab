<?php

declare(strict_types=1);

use App\Experiment\Experiment;
use App\Experiment\Options;
use App\Experiment\Output;

return new Experiment(
    name: 'process:lifecycle',
    description: 'exit codes, zombies, waitpid and signals',
    supports: [],
    run: static function (Options $options, Output $out): void {
        $out->heading('process lifecycle: exit statuses, zombies, signals');

        $waitFor = static function (int $pid, string $phase = '') use ($out): int {
            $waited = pcntl_waitpid($pid, $status);
            $suffix = $phase !== '' ? " ($phase)" : '';

            if (pcntl_wifsignaled($status)) {
                $out->write(sprintf(
                    "  waitpid -> %d%s, terminated by signal %d\n",
                    $waited,
                    $suffix,
                    pcntl_wtermsig($status),
                ));

                return $status;
            }

            $out->write(sprintf(
                "  waitpid -> %d%s, exit status %d\n",
                $waited,
                $suffix,
                pcntl_wexitstatus($status),
            ));

            return $status;
        };

        /*
         * 1. Normal exit: exit(0) -> wexitstatus 0.
         */
        $out->write("1) normal exit (exit(0)):\n");
        $pid = pcntl_fork();
        if ($pid === 0) {
            exit(0);
        }
        $waitFor($pid);

        /*
         * 2. Non-zero exit: exit(3) -> wexitstatus 3.
         */
        $out->write("\n2) non-zero exit (exit(3)):\n");
        $pid = pcntl_fork();
        if ($pid === 0) {
            exit(3);
        }
        $waitFor($pid);

        /*
         * 3. Zombie: the child exits but the parent delays waitpid; the state
         *    visible in /proc/<pid>/status is Z until the parent reaps it.
         */
        $out->write("\n3) zombie: child exits, parent delays waitpid:\n");
        $pid = pcntl_fork();
        if ($pid === 0) {
            exit(0);
        }

        sleep(1);
        $stateLine = '';
        foreach (file('/proc/' . $pid . '/status', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with($line, 'State:')) {
                $stateLine = trim($line);
                break;
            }
        }
        $out->write(sprintf("  child still unreaped, /proc/%d/status: %s\n", $pid, $stateLine));
        $waitFor($pid, 'reaped after the delay');

        /*
         * 4. Signal termination: the child blocks; the parent sends SIGTERM and the
         *    wait shows wifsignaled + wtermsig.
         */
        $out->write("\n4) killed by signal (SIGTERM):\n");
        $pid = pcntl_fork();
        if ($pid === 0) {
            sleep(30);
            exit(0);
        }

        usleep(200_000);
        posix_kill($pid, SIGTERM);
        $waitFor($pid, 'after SIGTERM');

        $out->note('an unreaped child is a zombie (Z) that holds its pid + exit state but no memory working set; a long-running supervisor must waitpid() to reap.');
        $out->context();
    },
);
