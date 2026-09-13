<?php

declare(strict_types=1);

use App\Experiment\Experiment;
use App\Experiment\Options;
use App\Experiment\Output;
use App\Memory\ByteFormatter;
use App\Memory\SmapsRollupReader;
use App\Native\MappedFile;

return new Experiment(
    name: 'mmap:modes',
    description: 'MAP_SHARED against MAP_PRIVATE, with PSS and msync',
    supports: ['size'],
    run: static function (Options $options, Output $out): void {
        $out->heading('MAP_SHARED against MAP_PRIVATE: the same call, two different machines');

        /*
         * One flag decides whether a mapping is shared memory or a private copy.
         *
         *   MAP_SHARED  - writes go to the page cache, which IS the file. Every other
         *                 process mapping it sees them, with no flush and no message.
         *   MAP_PRIVATE - the first write to a page faults a private copy of it. The
         *                 file never changes and nobody else ever sees anything.
         *
         * The second is Copy-on-Write applied to a file rather than to a fork, and it
         * accounts exactly like the fork case: Shared_Clean until written, then
         * Private_Dirty.
         */
        $path = \sys_get_temp_dir() . '/mmap-modes-' . \bin2hex(\random_bytes(4)) . '.bin';
        $size = $options->size(64 * 1024 * 1024);
        $smaps = new SmapsRollupReader();
        $original = \str_pad('untouched original', 24);

        foreach ([true, false] as $shared) {
            $label = $shared ? 'MAP_SHARED' : 'MAP_PRIVATE';

            // Reset the file each time, so the second run cannot be read as a
            // consequence of the first.
            \file_put_contents($path, $original);

            $mapping = MappedFile::open($path, $size, $shared);
            $mapping->write(0, \str_pad($label . ' was here', 24));

            $out->write(\sprintf("\n%s\n", $label));
            $out->write(\sprintf("  the writer itself reads: %s\n", \rtrim($mapping->read(0, 24))));

            $pid = \pcntl_fork();

            if ($pid === 0) {
                // A separate process mapping the same file by name - not a fork
                // inheriting a mapping, which would share it either way.
                $reader = MappedFile::open($path, $size, true);

                $out->write(\sprintf("  another process reads:   %s\n", \rtrim($reader->read(0, 24))));

                $reader->unmap();

                exit(0);
            }

            \pcntl_waitpid($pid, $status);

            $out->write(\sprintf(
                "  the file on disk says:   %s\n",
                \rtrim(\substr((string) \file_get_contents($path), 0, 24)),
            ));

            $mapping->unmap();
        }

        $out->write("\n");

        $out->note('the shared mapping changed the file for everyone without a single write() call and without a flush. The private one changed nothing outside the process that wrote it - it faulted its own copy of the page, exactly as a forked child does.');

        /*
         * And the accounting. Two processes mapping the same 64 MiB file hold one
         * copy of it between them, which RSS cannot express and PSS can.
         */
        $mapping = MappedFile::open($path, $size, true);
        $touched = 16 * 1024 * 1024;
        $pageSize = 4096;

        for ($offset = 0; $offset < $touched; $offset += $pageSize) {
            $mapping->read($offset, 1);
        }

        $parentBefore = $smaps->read();
        $pid = \pcntl_fork();

        if ($pid === 0) {
            $child = MappedFile::open($path, $size, true);

            for ($offset = 0; $offset < $touched; $offset += $pageSize) {
                $child->read($offset, 1);
            }

            $out->smaps('  child, reading the same 16.00 MiB ', $smaps->read());

            // Now write to it, which for MAP_SHARED dirties the shared page rather
            // than privatising it: the change is for everybody.
            for ($offset = 0; $offset < $touched; $offset += $pageSize) {
                $child->write($offset, 'w');
            }

            $out->smaps('  child, after writing to all of it  ', $smaps->read());
            \usleep(400_000);
            $child->unmap();

            exit(0);
        }

        \usleep(150_000);
        $out->smaps('  parent, while the child maps it too', $smaps->read());
        \pcntl_waitpid($pid, $status);

        $out->write(\sprintf(
            "\n  parent read %s of the file; Pss_File is what it really owns of it.\n",
            ByteFormatter::format($touched),
        ));

        $out->smaps('  parent, after the child exited     ', $smaps->read());

        /*
         * msync() is about durability, not visibility. The other process above saw
         * every byte without one.
         */
        $mapping->write(0, \str_repeat('d', 4096));
        $start = \hrtime(true);
        $mapping->flush();
        $flushMs = (\hrtime(true) - $start) / 1e6;

        $start = \hrtime(true);
        $mapping->flush();
        $cleanFlushMs = (\hrtime(true) - $start) / 1e6;

        $out->write(\sprintf(
            "\nmsync() with dirty pages: %.3f ms; immediately again, with nothing left dirty: %.3f ms\n",
            $flushMs,
            $cleanFlushMs,
        ));

        $mapping->unmap();
        \unlink($path);

        $out->note('msync() buys durability against a power cut, not visibility to other processes - they are looking at the same page cache this process is writing to, so they were never behind.');
        $out->note('a MAP_SHARED mapping is therefore shared memory with a filename: the same properties as phase 6\'s segment, minus the serialization, plus a lifetime managed by the filesystem instead of by ipcs.');
        $out->context();
    },
);
