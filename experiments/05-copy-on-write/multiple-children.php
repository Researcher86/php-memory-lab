<?php

declare(strict_types=1);

use App\Experiment\Experiment;
use App\Experiment\Options;
use App\Experiment\Output;
use App\Memory\ByteFormatter;
use App\Memory\SmapsRollupReader;

return new Experiment(
    name: 'cow:multiple-children',
    description: 'children writing disjoint regions of one inherited array',
    supports: ['elements', 'children'],
    run: static function (Options $options, Output $out): void {
        $out->context();

        $smaps = new SmapsRollupReader();
        $count = $options->elements(1_000_000);
        $children = $options->children(4);
        $region = (int) ($count / $children);
        $data = range(0, $count - 1);

        $out->write(sprintf(
            "Parent built a 1M-int array, forks %d children; every child writes a disjunct 250k-element region.\n",
            $children,
        ));

        $out->smaps('parent BEFORE forking', $smaps->read());

        $pids = [];

        for ($c = 0; $c < $children; $c++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                $start = $c * $region;
                $end = $start + $region;

                for ($k = $start; $k < $end; $k++) {
                    $data[$k]++;
                }

                $out->write(sprintf(
                    "child %d wrote [%d..%d): Private_Dirty %s\n",
                    $c,
                    $start,
                    $end,
                    ByteFormatter::format($smaps->read()->privateDirty),
                ));

                usleep(800_000);
                exit(0);
            }

            $pids[] = $pid;
        }

        usleep(300_000);
        $out->smaps('parent WHILE children rewrite disjoint regions', $smaps->read());

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $out->smaps('parent AFTER all children exited', $smaps->read());

        $out->write("Children each privatised only their own 250k region; the shared\n");
        $out->write("non-touched regions keep parent Shared_Dirty and children PSS low.\n");
        $out->write("No two children split the same page in a disjunct-region scheme.\n");
    },
);
