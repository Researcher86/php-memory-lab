<?php

declare(strict_types=1);

use App\Experiment\Experiment;
use App\Experiment\Options;
use App\Experiment\Output;
use App\Memory\MemoryReporter;

return new Experiment(
    name: 'memory:empty',
    description: 'empty-process baseline: before allocation, after allocation, after cleanup',
    supports: ['elements'],
    run: static function (Options $options, Output $out): void {
        $out->heading('empty-process baseline (Phase 1)');

        /*
         * The smallest thing worth measuring: what a PHP process holds before
         * it has done anything, what one array adds, and what unset() gives
         * back. The third of those is the interesting one - PHP memory drops
         * and RSS usually does not, because the allocator keeps the arena.
         */
        $elements = $options->elements(1_000_000);
        $reporter = new MemoryReporter();

        $before = $reporter->snapshot();
        $out->snapshot('Before', $before);

        $bucket = \range(1, $elements);
        $afterAllocation = $reporter->snapshot();
        $out->snapshot('After allocation', $afterAllocation);

        $allocation = $reporter->diff($before, $afterAllocation);
        $out->delta('allocation', $allocation);

        unset($bucket);
        $afterCleanup = $reporter->snapshot();
        $out->snapshot('After cleanup', $afterCleanup);

        $cleanup = $reporter->diff($afterAllocation, $afterCleanup);
        $out->delta('cleanup', $cleanup);

        $out->measure('elements', $elements);
        $out->measure('allocation_php_bytes', $allocation->phpUsage);
        $out->measure('allocation_rss_bytes', $allocation->rss);
        $out->measure('cleanup_php_bytes', $cleanup->phpUsage);
        $out->measure('cleanup_rss_bytes', $cleanup->rss);

        /*
         * Whether the RSS follows the PHP counter down is not a fixed fact,
         * which is why this is measured rather than asserted. A large
         * allocation is its own mmap()'d chunk and the allocator can hand the
         * whole thing back; a small one lives in an arena the allocator keeps
         * for the next request, and only the PHP counter moves.
         */
        $returnedToKernel = $cleanup->rss !== null && $cleanup->rss <= $cleanup->phpUsage / 2;

        $out->note(\sprintf(
            'PHP gave back %s bytes of the %s it took, and RSS moved by %s - %s.',
            \number_format(-$cleanup->phpUsage),
            \number_format($allocation->phpUsage),
            $cleanup->rss === null ? 'n/a' : \number_format($cleanup->rss),
            $returnedToKernel
                ? 'this block was large enough to be its own mapping, so the allocator returned it to the kernel'
                : 'the allocator kept the arena, so the process stays as large as its peak',
        ));
        $out->context();
    },
);
