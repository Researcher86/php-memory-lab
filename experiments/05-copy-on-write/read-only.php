<?php

declare(strict_types=1);

use App\Experiment\Experiment;
use App\Experiment\Options;
use App\Experiment\Output;
use App\Memory\MemoryReporter;
use App\Memory\SmapsRollupReader;

return new Experiment(
    name: 'cow:readonly',
    description: 'a child that only reads an inherited array copies no page',
    supports: ['elements'],
    run: static function (Options $options, Output $out): void {
        $out->context();

        $reporter = new MemoryReporter();
        $smaps = new SmapsRollupReader();

        $count = $options->elements(1_000_000);
        $data = range(0, $count - 1);

        $out->smaps('parent, after allocating 1M ints', $smaps->read());

        $pid = pcntl_fork();

        if ($pid === 0) {
            $sum = 0;

            for ($i = 0; $i < $count; $i++) {
                $sum += $data[$i];
            }

            $out->smaps('child, after reading whole array', $smaps->read());
            $out->write(sprintf("child read %d elements, sum=%d (array untouched)\n", $count, $sum));
            usleep(400_000);
            exit(0);
        }

        usleep(250_000);
        $out->smaps('parent, while child is reading (RSS should not move)', $smaps->read());

        pcntl_waitpid($pid, $status);
        $out->smaps('parent, after child exited', $smaps->read());
        $out->write("child exit status: " . pcntl_wexitstatus($status) . "\n");
    },
);
