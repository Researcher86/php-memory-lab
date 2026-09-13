<?php

declare(strict_types=1);

use App\Experiment\Experiment;
use App\Experiment\Options;
use App\Experiment\Output;
use App\Memory\MemoryReporter;
use App\Memory\SmapsRollupReader;

return new Experiment(
    name: 'cow:single-write',
    description: 'one element written, one page privatised',
    supports: ['elements'],
    run: static function (Options $options, Output $out): void {
        $out->context();

        $reporter = new MemoryReporter();
        $smaps = new SmapsRollupReader();

        $count = $options->elements(1_000_000);
        $data = range(0, $count - 1);

        $out->smaps('parent, after allocating 1M ints', $smaps->read());
        $out->write("forking child...\n");

        $pid = pcntl_fork();

        if ($pid === 0) {
            $beforeWrite = $smaps->read();
            $data[0] = 999;
            $afterWrite = $smaps->read();

            $out->smaps('child, BEFORE writing one element', $beforeWrite);
            $out->smaps('child, AFTER  writing $data[0] = 999', $afterWrite);

            /** @var int $dirtyDelta */
            $dirtyDelta = $afterWrite->privateDirty - $beforeWrite->privateDirty;
            $out->write(\sprintf(
                "child Private_Dirty delta: %s (+%d bytes) - the shared page holding \$data[0] became private\n",
                \App\Memory\ByteFormatter::format($dirtyDelta),
                $dirtyDelta,
            ));

            $out->write("child \$data[0] = {$data[0]}, \$data[999999] = {$data[999999]}\n");
            exit(0);
        }

        pcntl_waitpid($pid, $status);
        $out->write("parent verifies data still intact: \$data[0] = {$data[0]}, \$data[999999] = {$data[999999]}\n");
        $out->smaps('parent, after child wrote (own copy, parent unchanged)', $smaps->read());
    },
);
