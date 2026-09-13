#!/usr/bin/env php
<?php

declare(strict_types=1);

require \dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../run-helpers.php';

use App\Native\FfiBuffer;
use App\Native\Libc;
use App\Native\NativeMemoryException;

experiment_start('native memory ownership: the mistakes, and who reports them');

/*
 * CONTAINER ONLY. Every case below is undefined behaviour by design - a
 * double free, a use-after-free, a write past the end of an allocation. They
 * are here because "undefined" is not an abstraction until you have watched
 * what it actually does, and because the answer is interestingly inconsistent:
 * some of these kill the process instantly and some of them succeed quietly
 * and take effect somewhere else entirely.
 *
 * Each case runs in its own forked child, so the experiment survives them.
 * Nothing here should ever run on a machine that matters.
 */

/**
 * Runs $case in a child and reports how that child ended.
 */
function contained(string $title, callable $case): void
{
    $pid = \pcntl_fork();

    if ($pid === 0) {
        $case();

        exit(0);
    }

    \pcntl_waitpid($pid, $status);

    if (\pcntl_wifsignaled($status)) {
        $signal = \pcntl_wtermsig($status);
        $outcome = \sprintf(
            'killed by signal %d (%s)',
            $signal,
            match ($signal) {
                SIGABRT => 'SIGABRT - the allocator caught it',
                SIGSEGV => 'SIGSEGV - an address that is not mapped',
                SIGBUS => 'SIGBUS',
                default => 'unexpected',
            },
        );
    } else {
        $code = \pcntl_wexitstatus($status);
        $outcome = $code === 0 ? 'finished normally - nothing noticed' : \sprintf('exited with code %d', $code);
    }

    \fwrite(STDOUT, \sprintf("  %-30s %s\n", $title, $outcome));
}

\fwrite(STDOUT, "\nEach case runs in its own child. Lines starting with \"free():\" or\n");
\fwrite(STDOUT, "\"malloc():\" below come from glibc, not from PHP - the allocator is the\n");
\fwrite(STDOUT, "only thing in the stack that has any opinion about these.\n\n");

contained('allocate and free once', static function (): void {
    $pointer = Libc::malloc(1024);

    if ($pointer !== null) {
        FFI::memcpy($pointer, \str_repeat('a', 1024), 1024);
        Libc::free($pointer);
    }
});

contained('free the same pointer twice', static function (): void {
    $pointer = Libc::malloc(1024);

    if ($pointer !== null) {
        Libc::free($pointer);
        Libc::free($pointer);
    }
});

contained('write after free', static function (): void {
    $pointer = Libc::malloc(1024);

    if ($pointer !== null) {
        Libc::free($pointer);

        // The address is still a valid number and the pages are still mapped.
        // Nothing stops the write; the allocator has simply promised those
        // bytes to somebody else by now.
        FFI::memcpy($pointer, \str_repeat('z', 1024), 1024);
    }
});

contained('read after free', static function (): void {
    $pointer = Libc::malloc(1024);

    if ($pointer !== null) {
        FFI::memcpy($pointer, \str_repeat('r', 1024), 1024);
        Libc::free($pointer);

        $bytes = Libc::cast('char *', $pointer);
        FFI::string(FFI::addr($bytes[0]), 16);
    }
});

contained('write 4 KiB into 64 bytes', static function (): void {
    $pointer = Libc::malloc(64);

    if ($pointer !== null) {
        // Straight over the allocator's bookkeeping for whatever follows.
        FFI::memcpy($pointer, \str_repeat('o', 4096), 4096);
        Libc::free($pointer);
    }
});

contained('overflow, then allocate again', static function (): void {
    $first = Libc::malloc(64);

    if ($first !== null) {
        FFI::memcpy($first, \str_repeat('o', 4096), 4096);
    }

    // The corruption was silent; this is where it is noticed, in a call that
    // has nothing to do with the code that caused it.
    for ($i = 0; $i < 64; $i++) {
        $next = Libc::malloc(1024);

        if ($next !== null) {
            Libc::free($next);
        }
    }
});

\fwrite(STDOUT, "\nThe same three mistakes through FfiBuffer:\n");

foreach ([
    'free twice' => static function (): void {
        $buffer = FfiBuffer::allocate(1024);
        $buffer->free();
        $buffer->free();
    },
    'use after free' => static function (): void {
        $buffer = FfiBuffer::allocate(1024);
        $buffer->free();
        $buffer->write(0, 'gone');
    },
    'write past the end' => static function (): void {
        FfiBuffer::allocate(64)->write(0, \str_repeat('o', 4096));
    },
] as $title => $case) {
    try {
        $case();
        \fwrite(STDOUT, \sprintf("  %-30s allowed, and survived - the wrapper made it safe\n", $title));
    } catch (NativeMemoryException $e) {
        \fwrite(STDOUT, \sprintf("  %-30s refused: %s\n", $title, $e->getMessage()));
    }
}

experiment_note('the interesting result is not that these are dangerous - it is how little the severity of a mistake has to do with whether anything reports it. The double free was caught instantly and by name. The 4 KiB write into a 64-byte allocation was not reported by the write, not by the free of the corrupted chunk, and not by sixty-four allocations afterwards: the worst of these is the one nothing notices.');
experiment_note('read-after-free also finished quietly, returning whatever the allocator had done with those bytes since. In a longer-lived process those bytes belong to something else by then, and the read is somebody else\'s data - which is the shape of a real vulnerability rather than a crash.');
experiment_note('none of it is visible from PHP. There is no exception to catch, no error handler, and no diagnostic beyond a line glibc writes to stderr on its way out - which is why FfiBuffer validates before the access rather than trying to recover after one.');
experiment_note('and why every check it performs is a check on a number, not on the memory: the length it compares against lives in the PHP object, because the allocation itself has never known how big it is.');
experiment_context();
