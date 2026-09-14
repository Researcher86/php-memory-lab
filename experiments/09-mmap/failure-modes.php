<?php

declare(strict_types=1);

use App\Experiment\Experiment;
use App\Experiment\Options;
use App\Experiment\Output;
use App\Experiment\ScratchFile;
use App\Native\MappedFile;
use App\Native\NativeMemoryException;

return new Experiment(
    name: 'mmap:failures',
    description: 'bounds, double unmap, and SIGBUS on truncation',
    supports: [],
    run: static function (Options $options, Output $out): void {
        $out->heading('mmap: the errors PHP can report, and the ones it cannot');

        /*
         * A mapping is a region of this process's address space with no runtime
         * behind it. Nothing checks an access; the CPU either finds a page table
         * entry or raises a fault, and what the kernel does about that fault is not
         * negotiable from userland.
         *
         * So the failures split cleanly in two. Everything MappedFile can compute in
         * advance - offsets, lengths, whether the region still exists - is refused
         * with an exception. Everything else kills the process, and the only
         * defensible way to demonstrate it is inside a child that is expected to die.
         */
        $path = ScratchFile::reserve('mmap-failures');
        ScratchFile::removeOnExit($path . '.zero');
        $mapping = MappedFile::open($path, 8192);

        $out->write("\nRefused before the access happens:\n");

        foreach ([
            'read past the end' => static fn () => $mapping->read(8190, 10),
            'write past the end' => static fn () => $mapping->write(8100, str_repeat('x', 200)),
            'negative offset' => static fn () => $mapping->read(-8, 8),
            'negative length' => static fn () => $mapping->read(0, -1),
            'a zero-byte mapping' => static fn () => MappedFile::open($path . '.zero', 0),
        ] as $title => $case) {
            try {
                $case();
                $out->write(sprintf("  %-22s ALLOWED IT - no check fired\n", $title));
            } catch (NativeMemoryException $e) {
                $out->write(sprintf("  %-22s %s\n", $title, $e->getMessage()));
            }
        }

        $mapping->unmap();
        $mapping->unmap();
        $out->write("  unmapping twice        second call is a no-op; a second munmap() of the same address is not\n");

        try {
            $mapping->read(0, 1);
        } catch (NativeMemoryException $e) {
            $out->write(sprintf("  %-22s %s\n", 'reading after unmap', $e->getMessage()));
        }

        /*
         * And the one that cannot be refused. A mapping outlives the file's size:
         * truncate the file and the pages past the new end still exist in the page
         * table, but the kernel has nothing to back them with. Touching one raises
         * SIGBUS, whose default action is to kill the process - there is no return
         * value, no errno and nothing for PHP to catch.
         *
         * Contained in a forked child on purpose. This is the category of experiment
         * that only belongs in a disposable container.
         */
        $mapping = MappedFile::open($path, 1024 * 1024);
        $mapping->write(512 * 1024, 'still inside the file');
        $out->write(sprintf(
            "\nMapped %s of %s and read offset 512 KiB back: \"%s\"\n",
            '1.00 MiB',
            basename($path),
            $mapping->read(512 * 1024, 21),
        ));

        $pid = pcntl_fork();

        if ($pid === 0) {
            $victim = MappedFile::open($path, 1024 * 1024);

            // The file shrinks under a mapping that still covers the old size.
            file_put_contents($path, 'tiny');

            $out->write("  child: file truncated to 4 bytes, mapping still 1.00 MiB - touching offset 512 KiB now\n");
            $victim->read(512 * 1024, 8);

            $out->write("  child: survived, which was not the expectation\n");

            exit(0);
        }

        pcntl_waitpid($pid, $status);

        if (pcntl_wifsignaled($status)) {
            $out->write(sprintf(
                "  child died from signal %d (%s) - no exception, no errno, no stack trace\n",
                pcntl_wtermsig($status),
                pcntl_wtermsig($status) === SIGBUS ? 'SIGBUS' : 'unexpected',
            ));
        } else {
            $out->write(sprintf("  child exited normally with code %d\n", pcntl_wexitstatus($status)));
        }

        $mapping->unmap();
        @unlink($path);
        @unlink($path . '.zero');

        $out->note('the parent is untouched: the mapping, the fault and the signal all belong to the process that made the access, which is why a child is the right container for it.');
        $out->note('a bounds check in PHP is therefore not defensive programming, it is the only layer there is. Past the check, the next thing that notices a bad address is the MMU.');
        $out->note('it also means a mapped file must not be truncated while anyone has it mapped - the usual answer is to write a new file and rename it over the old one, which leaves existing mappings pointing at the old inode until they are dropped.');
        $out->context();
    },
);
