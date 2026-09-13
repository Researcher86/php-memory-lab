<?php

declare(strict_types=1);

use App\Memory\MemoryReporter;
use App\Memory\SmapsRollupReader;

require __DIR__ . '/../run-helpers.php';

experiment_context();

$reporter = new MemoryReporter();
$smaps = new SmapsRollupReader();

$count = 1_000_000;
$data = range(0, $count - 1);

experiment_smaps('parent, after allocating 1M ints', $smaps->read());

$pid = pcntl_fork();

if ($pid === 0) {
    $sum = 0;

    for ($i = 0; $i < $count; $i++) {
        $sum += $data[$i];
    }

    experiment_smaps('child, after reading whole array', $smaps->read());
    \fwrite(STDOUT, \sprintf("child read %d elements, sum=%d (array untouched)\n", $count, $sum));
    usleep(400_000);
    exit(0);
}

usleep(250_000);
experiment_smaps('parent, while child is reading (RSS should not move)', $smaps->read());

pcntl_waitpid($pid, $status);
experiment_smaps('parent, after child exited', $smaps->read());
\fwrite(STDOUT, "child exit status: " . pcntl_wexitstatus($status) . "\n");