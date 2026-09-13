<?php

declare(strict_types=1);

use App\Experiment\Experiment;
use App\Experiment\Options;
use App\Experiment\Output;
use App\Memory\ByteFormatter;
use App\Memory\MemoryReporter;

return new Experiment(
    name: 'process:fork',
    description: 'one fork, with parent and child measured independently',
    supports: [],
    run: static function (Options $options, Output $out): void {
        $out->heading('fork: parent and child measured independently');

        $reporter = new MemoryReporter();

        $before = $reporter->snapshot();

        $start = microtime(true);
        $pid = pcntl_fork();
        $forkTimeMs = (microtime(true) - $start) * 1000;

        if ($pid === -1) {
            fwrite(STDERR, "fork() failed\n");
            exit(1);
        }

        if ($pid === 0) {
            $child = $reporter->snapshot();
            $out->write(sprintf("\n--- child (pid %d, parent %d) ---\n", getmypid(), posix_getppid()));
            $out->write(sprintf("  PHP usage: %s\n", ByteFormatter::format($child->phpUsage)));
            $out->write(sprintf("  RSS:       %s\n", ByteFormatter::format($child->rss ?? 0)));
            $out->write(sprintf("  PSS:       %s\n", ByteFormatter::format($child->pss ?? 0)));
            $out->write(sprintf("  VmSize:    %s\n", ByteFormatter::format($child->virtualMemory ?? 0)));
            exit(0);
        }

        $waited = pcntl_waitpid($pid, $status);

        $out->write(sprintf(
            "\nparent waitpid(%d) returned %d, raw status %d, exit status %d\n",
            $pid,
            $waited,
            $status,
            pcntl_wexitstatus($status),
        ));

        $after = $reporter->snapshot();
        $out->write(sprintf("fork() took %.2f ms\n", $forkTimeMs));
        $out->delta('parent after waiting for the child', $reporter->diff($before, $after));

        $out->note('parent and child each ran their own snapshot; identical VmSize (the child inherits the address space), independent PID.');
        $out->context();
    },
);
