<?php

declare(strict_types=1);

use App\Experiment\Experiment;
use App\Experiment\Options;
use App\Experiment\Output;
use App\Memory\ByteFormatter;
use App\Memory\MemoryReporter;
use App\Memory\ProcStatusReader;

return new Experiment(
    name: 'process:multiple-forks',
    description: 'one to sixteen children: fork time, RSS and PSS',
    supports: [],
    run: static function (Options $options, Output $out): void {
        $out->heading('fork: one / two / four / eight / sixteen children');

        /*
         * Each child stays alive (sleep) while the parent snapshots itself and reads
         * every child's VmRSS from /proc/<pid>/status. RSS is counted per process, so
         * shared pages appear in several rows at once; PSS divides them.
         */
        $reporter = new MemoryReporter();
        $statusReader = new ProcStatusReader();

        $forkChild = static function () {
            usleep(800_000);
            exit(0);
        };

        foreach ([1, 2, 4, 8, 16] as $childrenCount) {
            $baseline = $reporter->snapshot();
            $start = microtime(true);

            $children = [];
            for ($i = 0; $i < $childrenCount; ++$i) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    fwrite(STDERR, "fork() failed\n");
                    exit(1);
                }
                if ($pid === 0) {
                    $forkChild();
                }
                $children[] = $pid;
            }

            $forkTimeMs = (microtime(true) - $start) * 1000;

            usleep(150_000); // let the children run their tiny sleep
            $during = $reporter->snapshot();

            $childrenRss = 0;
            foreach ($children as $pid) {
                $status = $statusReader->read($pid);
                $childrenRss += (int) $status['VmRSS'];
            }

            $totalRss = (int) $during->rss + $childrenRss;

            $out->write(sprintf(
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
                pcntl_waitpid($pid, $status);
            }
        }

        $out->write("\n");
        $out->note('"approx total RSS" sums per-process RSS, so it double-counts the pages the children share with the parent; PSS is the honest total.');
        $out->context();
    },
);
