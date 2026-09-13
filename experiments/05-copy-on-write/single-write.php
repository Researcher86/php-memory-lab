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
\fwrite(STDOUT, "forking child...\n");

$pid = pcntl_fork();

if ($pid === 0) {
    $beforeWrite = $smaps->read();
    $data[0] = 999;
    $afterWrite = $smaps->read();

    experiment_smaps('child, BEFORE writing one element', $beforeWrite);
    experiment_smaps('child, AFTER  writing $data[0] = 999', $afterWrite);

    /** @var int $dirtyDelta */
    $dirtyDelta = $afterWrite->privateDirty - $beforeWrite->privateDirty;
    \fwrite(STDOUT, \sprintf(
        "child Private_Dirty delta: %s (+%d bytes) - the shared page holding \$data[0] became private\n",
        \App\Memory\ByteFormatter::format($dirtyDelta),
        $dirtyDelta,
    ));

    \fwrite(STDOUT, "child \$data[0] = {$data[0]}, \$data[999999] = {$data[999999]}\n");
    exit(0);
}

pcntl_waitpid($pid, $status);
\fwrite(STDOUT, "parent verifies data still intact: \$data[0] = {$data[0]}, \$data[999999] = {$data[999999]}\n");
experiment_smaps('parent, after child wrote (own copy, parent unchanged)', $smaps->read());