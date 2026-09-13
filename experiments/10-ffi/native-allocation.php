#!/usr/bin/env php
<?php

declare(strict_types=1);

require \dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../run-helpers.php';

use App\Memory\ByteFormatter;
use App\Native\FfiBuffer;
use App\Native\Libc;

experiment_start('native memory: what the PHP counters cannot see');

/*
 * malloc() through FFI allocates from libc's heap. The PHP engine is not
 * involved, does not account for it, and will not free it - which makes this
 * the clearest case of the gap the whole project is about: PHP memory and
 * process memory are different numbers, and here one of them does not move at
 * all.
 */
$reporter = experiment_reporter();
$size = 256 * 1024 * 1024;
$pageSize = Libc::pageSize();

\fwrite(STDOUT, \sprintf("\nPHP memory_limit: %s\n", (string) \ini_get('memory_limit')));

$before = $reporter->snapshot();
$buffer = FfiBuffer::allocate($size);
$allocated = $reporter->diff($before, $reporter->snapshot());

\fwrite(STDOUT, \sprintf(
    "\nmalloc(%s):\n  PHP usage %s   RSS %s   VmSize %s\n",
    ByteFormatter::format($size),
    ByteFormatter::formatSigned($allocated->phpUsage),
    $allocated->rss === null ? 'n/a' : ByteFormatter::formatSigned($allocated->rss),
    $allocated->virtualMemory === null ? 'n/a' : ByteFormatter::formatSigned($allocated->virtualMemory),
));

experiment_note('the allocation succeeded well past a 128M memory_limit, because the limit governs the PHP allocator and this is not it. Nothing in memory_get_usage() will ever mention these bytes.');
experiment_note('RSS has not moved either: for an allocation this size libc goes to mmap(), which - exactly as in phase 8 - reserves address space and touches no page.');

/*
 * Touching it is what costs. One byte per page is enough: the fault brings in
 * the page whether one byte or four thousand are written.
 */
$touchBefore = $reporter->snapshot();

for ($offset = 0; $offset < $size; $offset += $pageSize) {
    $buffer->write($offset, 'x');
}

$touched = $reporter->diff($touchBefore, $reporter->snapshot());

\fwrite(STDOUT, \sprintf(
    "\nAfter writing one byte per page:\n  PHP usage %s   RSS %s\n",
    ByteFormatter::formatSigned($touched->phpUsage),
    $touched->rss === null ? 'n/a' : ByteFormatter::formatSigned($touched->rss),
));

$freeBefore = $reporter->snapshot();
$buffer->free();
$freed = $reporter->diff($freeBefore, $reporter->snapshot());

\fwrite(STDOUT, \sprintf(
    "\nAfter free():\n  PHP usage %s   RSS %s\n",
    ByteFormatter::formatSigned($freed->phpUsage),
    $freed->rss === null ? 'n/a' : ByteFormatter::formatSigned($freed->rss),
));

experiment_note('the whole 256.00 MiB went back at once. A large allocation is an mmap() that free() unmaps, which is why it returns to the OS immediately - unlike the PHP allocator, which keeps freed arenas (see docs/memory-model.md).');

/*
 * The same size as a PHP value, for contrast.
 */
\ini_set('memory_limit', '512M');
$stringBefore = $reporter->snapshot();
$string = \str_repeat('x', $size);
$stringDelta = $reporter->diff($stringBefore, $reporter->snapshot());

\fwrite(STDOUT, \sprintf(
    "\nA PHP string of the same %s (memory_limit raised to 512M first):\n  PHP usage %s   RSS %s\n",
    ByteFormatter::format($size),
    ByteFormatter::formatSigned($stringDelta->phpUsage),
    $stringDelta->rss === null ? 'n/a' : ByteFormatter::formatSigned($stringDelta->rss),
));

unset($string);

/*
 * And the failure mode that follows from PHP not owning the memory: an
 * allocation whose last reference is dropped without a free() is simply gone
 * - reachable by nobody, returned by nothing, until the process exits.
 */
$megabyte = \str_repeat('L', 1024 * 1024);
$leakBefore = $reporter->snapshot();

for ($i = 0; $i < 64; $i++) {
    $leaked = Libc::malloc(1024 * 1024);

    if ($leaked !== null) {
        // Touch all of it, so the pages are real rather than promised, and
        // then drop the only reference to the address.
        FFI::memcpy($leaked, $megabyte, 1024 * 1024);
    }

    unset($leaked);
}

$leakDelta = $reporter->diff($leakBefore, $reporter->snapshot());

\fwrite(STDOUT, \sprintf(
    "\n64 x 1.00 MiB malloc'd through libc directly and never freed:\n  PHP usage %s   RSS %s\n",
    ByteFormatter::formatSigned($leakDelta->phpUsage),
    $leakDelta->rss === null ? 'n/a' : ByteFormatter::formatSigned($leakDelta->rss),
));

experiment_note('dropping the PHP variable freed nothing: the pointer was a number, and losing it lost the only way to ever call free() on that address. This is the leak FfiBuffer\'s destructor exists to prevent - and the reason a long-running process with flat PHP memory can still grow without bound.');
experiment_note('note which counter noticed. PHP usage did not move for any of it; RSS moved for all of it. An application watching memory_get_usage() to decide when to recycle a worker would see nothing at all here.');
experiment_context();
