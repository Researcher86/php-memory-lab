<?php

declare(strict_types=1);

use App\Experiment\Experiment;
use App\Experiment\Options;
use App\Experiment\Output;
use App\Experiment\ScratchFile;
use App\Memory\ByteFormatter;
use App\Memory\MemoryReporter;
use App\Native\Libc;
use App\Native\MappedFile;

return new Experiment(
    name: 'mmap:lazy',
    description: 'a mapping is a promise, not a read',
    supports: ['size'],
    run: static function (Options $options, Output $out): void {
        $out->heading('mmap: a mapping is a promise, not a read');

        /*
         * mmap() does not read the file. It writes an entry in the page table saying
         * "these addresses come from that file" and returns. Nothing is fetched until
         * something touches a page, and then exactly one page is fetched.
         *
         * So a mapping costs address space, which is free, instead of memory, which
         * is not - and the difference between the two is measurable in one run.
         */
        $path = ScratchFile::reserve('mmap-lazy');
        $size = $options->size(256 * 1024 * 1024);
        $pageSize = Libc::pageSize();

        /** @return array{minor: int, major: int} */
        function faults(): array
        {
            $usage = getrusage();

            return ['minor' => $usage['ru_minflt'], 'major' => $usage['ru_majflt']];
        }

        // A file of known content, written once so the page cache is warm and the
        // numbers below measure mapping rather than disk.
        $chunk = str_repeat('m', 1024 * 1024);
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('unable to create ' . $path);
        }

        for ($written = 0; $written < $size; $written += strlen($chunk)) {
            fwrite($handle, $chunk);
        }

        fclose($handle);

        $reporter = new MemoryReporter();

        $out->write(sprintf(
            "\nFile: %s of %s, page size %s, PHP memory_limit %s\n",
            basename($path),
            ByteFormatter::format($size),
            ByteFormatter::format($pageSize),
            (string) ini_get('memory_limit'),
        ));

        $before = $reporter->snapshot();
        $faultsBefore = faults();

        $mapping = MappedFile::open($path, $size);

        $afterMap = $reporter->snapshot();
        $mapDelta = $reporter->diff($before, $afterMap);

        $out->write(sprintf(
            "\nAfter mmap() of the whole file:\n  VmSize %s   RSS %s   PHP usage %s   minor faults %+d\n",
            $mapDelta->virtualMemory === null ? 'n/a' : ByteFormatter::formatSigned($mapDelta->virtualMemory),
            $mapDelta->rss === null ? 'n/a' : ByteFormatter::formatSigned($mapDelta->rss),
            ByteFormatter::formatSigned($mapDelta->phpUsage),
            faults()['minor'] - $faultsBefore['minor'],
        ));

        $out->note('the address space grew by the size of the file and the resident memory did not. Nothing has been read yet.');
        $out->note('note also that a 256.00 MiB mapping succeeded under a 128M memory_limit. The limit counts what the PHP allocator hands out, and a mapping is not that - which is the first of several ways this phase and the next make memory that memory_get_usage() cannot see.');

        /*
         * Now touch it. Each first touch of a page is a fault the kernel serves from
         * the page cache - a minor fault, because the data is already in memory, just
         * not in this process's page table.
         */
        foreach ([1, 16, 256, 4096] as $pages) {
            $touchBefore = $reporter->snapshot();
            $faultsBefore = faults();
            $start = hrtime(true);
            $sum = 0;

            for ($page = 0; $page < $pages; $page++) {
                $sum += strlen($mapping->read($page * $pageSize, 1));
            }

            $elapsedMs = (hrtime(true) - $start) / 1e6;
            $touchDelta = $reporter->diff($touchBefore, $reporter->snapshot());
            $faultDelta = faults()['minor'] - $faultsBefore['minor'];

            $out->write(sprintf(
                "  touched %6s pages (%9s): RSS %10s   minor faults %+6d   %9s per fault   %6.2f ms\n",
                number_format($pages),
                ByteFormatter::format($pages * $pageSize),
                $touchDelta->rss === null ? 'n/a' : ByteFormatter::formatSigned($touchDelta->rss),
                $faultDelta,
                $faultDelta > 0 && $touchDelta->rss !== null
                    ? ByteFormatter::format((int) ($touchDelta->rss / $faultDelta))
                    : '-',
                $elapsedMs,
            ));
        }

        $out->note('RSS follows what was touched, but not one page at a time: the fault count is far below the page count, and each fault brings in close to a megabyte. The kernel reads ahead and maps whole folios, so a sequential walk pays for one fault per large run rather than one per page.');
        $out->note('which is why the rows with no faults at all still cost time - those pages were already mapped by the fault before them, and reading them is just memory access.');

        $mapping->unmap();
        $afterUnmap = $reporter->snapshot();

        $out->write(sprintf(
            "\nAfter munmap():\n  VmSize %s   RSS %s\n",
            ($d = $reporter->diff($afterMap, $afterUnmap))->virtualMemory === null ? 'n/a' : ByteFormatter::formatSigned($d->virtualMemory),
            $d->rss === null ? 'n/a' : ByteFormatter::formatSigned($d->rss),
        ));

        $out->note('munmap() gave back every page that had been touched in one call - the RSS delta across map, touch and unmap is back to noise. Unlike freed PHP memory, which the allocator keeps, an unmapped region is gone from the process immediately.');

        /*
         * The comparison that makes the point. Reading the same file into a PHP
         * string costs its full size in RSS and in PHP memory, immediately, whether
         * or not anything looks at the far end of it.
         */
        // Raised only for this comparison, and only because the string needs it.
        // The mapping above ran under the default limit untouched.
        ini_set('memory_limit', '512M');

        $readBefore = $reporter->snapshot();
        $start = hrtime(true);
        $contents = file_get_contents($path);
        $readMs = (hrtime(true) - $start) / 1e6;
        $readDelta = $reporter->diff($readBefore, $reporter->snapshot());

        $out->write(sprintf(
            "\nfile_get_contents() of the same %s (memory_limit raised to 512M to allow it):\n  PHP usage %s   RSS %s   %.1f ms\n",
            ByteFormatter::format($size),
            ByteFormatter::formatSigned($readDelta->phpUsage),
            $readDelta->rss === null ? 'n/a' : ByteFormatter::formatSigned($readDelta->rss),
            $readMs,
        ));

        unset($contents);
        unlink($path);

        $out->note('the mapping paid for the 16.00 MiB it touched; file_get_contents() paid for all 256.00 MiB, because a PHP string has no way to be partly present - and had to be allowed a larger memory_limit to do it.');
        $out->note('this is why a mapped file is the right shape for a large index, a database page cache or a read-mostly dataset, and the wrong shape for anything that is going to be scanned once from end to end anyway.');
        $out->context();
    },
);
