<?php

declare(strict_types=1);

use App\Memory\ByteFormatter;
use App\Memory\MemoryReporter;
use App\Memory\SmapsRollupReader;

require __DIR__ . '/../run-helpers.php';

experiment_context();

$count = 1_000_000;

/**
 * Scenarios both run as children: the parent allocates the 1M-int array and
 * the child mutates it, so the fork debt is the same in both rows and only
 * the mutation strategy differs.
 */
$runChild = static function (string $label, callable $mutate) use ($count): void {
    $reporter = new MemoryReporter();
    $smaps = new SmapsRollupReader();

    $data = range(0, $count - 1);
    $start = hrtime(true);

    $pid = pcntl_fork();

    if ($pid === 0) {
        $memBefore = $reporter->snapshot();
        $smapsBefore = $smaps->read();

        $mutate($data);

        $memAfter = $reporter->snapshot();
        $smapsAfter = $smaps->read();

        $elapsedMs = (hrtime(true) - $start) / 1e6;

        \fwrite(STDOUT, \sprintf(
            "%-28s time %8.2f ms | PHP %s | RSS %s | Private_Dirty %s (%+d kB) | Shared_Dirty %s\n",
            $label,
            $elapsedMs,
            ByteFormatter::format($memAfter->phpUsage - $memBefore->phpUsage),
            ByteFormatter::format($smapsAfter->rss - $smapsBefore->rss),
            ByteFormatter::format($smapsAfter->privateDirty - $smapsBefore->privateDirty),
            (int) (($smapsAfter->privateDirty - $smapsBefore->privateDirty) / 1024),
            ByteFormatter::format($smapsAfter->sharedDirty - $smapsBefore->sharedDirty),
        ));

        exit(0);
    }

    pcntl_waitpid($pid, $status);
};

\fwrite(STDOUT, "Rewriting the inherited array in a child (each row a fresh fork + mutation by reference).\n");
\fwrite(STDOUT, \sprintf("(parent array: %d integers; RSS stays flat for COW -- watch Private_Dirty/Shared_Dirty)\n", $count));

$runChild('in-place: $data[$k] ++', static function (array &$data): void {
    for ($k = 0, $n = \count($data); $k < $n; $k++) {
        $data[$k]++;
    }
});

$runChild('fresh: $data = range(...)', static function (array &$data): void {
    $data = range(1, \count($data));
});

\fwrite(STDOUT, "In-place rewrites the shared pages, privatising each one it touches (OS copy-on-write).\n");
\fwrite(STDOUT, "Fresh discards the shared array in the child and allocates a private one (allocator may reuse the freed shared pages).\n");