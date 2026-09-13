<?php

declare(strict_types=1);

use App\Experiment\Experiment;
use App\Experiment\Options;
use App\Experiment\Output;
use App\Memory\ByteFormatter;
use App\Memory\SmapsRollupReader;

return new Experiment(
    name: 'cow:many-writes',
    description: '1 / 1k / 10k / 1M writes against private dirty memory',
    supports: ['elements'],
    run: static function (Options $options, Output $out): void {
        $out->context();

        $count = $options->elements(1_000_000);

        /**
         * Forks a child that writes every $stride-th element of the inherited array
         * and reports how many pages became private.
         */
        $runChild = static function (int $stride) use ($count): void {
            $smaps = new SmapsRollupReader();

            $data = range(0, $count - 1);
            $start = hrtime(true);

            $pid = pcntl_fork();

            if ($pid === 0) {
                $before = $smaps->read();
                $writes = 0;

                for ($k = 0; $k < $count; $k += $stride) {
                    $data[$k] += 1;
                    $writes++;
                }

                $after = $smaps->read();

                $elapsedMs = (hrtime(true) - $start) / 1e6;
                $dirtyDelta = $after->privateDirty - $before->privateDirty;
                $sharedDirtyDelta = $after->sharedDirty - $before->sharedDirty;

                $out->write(wordwrap(\sprintf(
                    "[%d writes] %d elements in place. Time %.2f ms | Private_Dirty +%s (+%dB) | Shared_Dirty %s (%dB) | RSS +%s\n",
                    $writes,
                    $count,
                    $elapsedMs,
                    ByteFormatter::format($dirtyDelta),
                    $dirtyDelta,
                    $sharedDirtyDelta >= 0 ? '+' . ByteFormatter::format($sharedDirtyDelta) : '-' . ByteFormatter::format(-$sharedDirtyDelta),
                    $sharedDirtyDelta,
                    ByteFormatter::format($after->rss - $before->rss),
                ), 120) . "\n");

                exit(0);
            }

            pcntl_waitpid($pid, $status);
        };

        $out->write("Each row is a fresh fork: the parent builds a 1M-int array, the child writes N elements.\n");
        $runChild($count);          // 1 write
        $runChild((int) ($count / 1000)); // ~1_000 writes
        $runChild((int) ($count / 100));  // 10_000 writes
        $runChild(1);               // 1_000_000 writes (every element)
    },
);
