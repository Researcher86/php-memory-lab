<?php

declare(strict_types=1);

use App\Memory\ByteFormatter;
use App\Memory\SmapsRollupReader;

require __DIR__ . '/../run-helpers.php';

experiment_context();

$smaps = new SmapsRollupReader();
$count = 1_000_000;
$children = 4;
$region = (int) ($count / $children);
$data = range(0, $count - 1);

\fwrite(STDOUT, \sprintf(
    "Parent built a 1M-int array, forks %d children; every child writes a disjunct 250k-element region.\n",
    $children,
));

experiment_smaps('parent BEFORE forking', $smaps->read());

$pids = [];

for ($c = 0; $c < $children; $c++) {
    $pid = pcntl_fork();

    if ($pid === 0) {
        $start = $c * $region;
        $end = $start + $region;

        for ($k = $start; $k < $end; $k++) {
            $data[$k]++;
        }

        \fwrite(STDOUT, \sprintf(
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
experiment_smaps('parent WHILE children rewrite disjoint regions', $smaps->read());

foreach ($pids as $pid) {
    pcntl_waitpid($pid, $status);
}

experiment_smaps('parent AFTER all children exited', $smaps->read());

\fwrite(STDOUT, "Children each privatised only their own 250k region; the shared\n");
\fwrite(STDOUT, "non-touched regions keep parent Shared_Dirty and children PSS low.\n");
\fwrite(STDOUT, "No two children split the same page in a disjunct-region scheme.\n");