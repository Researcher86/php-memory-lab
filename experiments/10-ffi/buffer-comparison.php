#!/usr/bin/env php
<?php

declare(strict_types=1);

require \dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../run-helpers.php';

use App\Memory\ByteFormatter;
use App\Native\FfiBuffer;
use App\Native\MappedFile;

experiment_start('four ways to hold 16 MiB, measured the same way');

/*
 * The same sixteen megabytes, held as a PHP string, a PHP array of chunks, a
 * malloc'd buffer and a mapped file. Everything is done in 64 KiB blocks so
 * the comparison is about the container and not about who pays more PHP call
 * overhead per byte.
 *
 * Four things are worth reading off the table, and only one of them is speed.
 */
$total = 16 * 1024 * 1024;
$block = 64 * 1024;
$blocks = \intdiv($total, $block);
$chunk = \str_repeat('x', $block);

// One source of bytes, built once and outside every measurement, so that the
// array row below can take a real copy of its data rather than a second
// reference to the same zval - which would let it hold "16 MiB" for the price
// of 256 refcount increments and make the column meaningless.
$source = \str_repeat('s', $total);
$reporter = experiment_reporter();
$path = \sys_get_temp_dir() . '/ffi-compare-' . \bin2hex(\random_bytes(4)) . '.bin';

/**
 * @param callable(): mixed             $allocate
 * @param callable(mixed, int): void    $write
 * @param callable(mixed, int): string  $read
 * @param callable(mixed): void         $release
 */
function measure(
    string $name,
    int $blocks,
    callable $allocate,
    callable $write,
    callable $read,
    callable $release,
): void {
    global $reporter;

    \gc_collect_cycles();
    $before = $reporter->snapshot();

    $start = \hrtime(true);
    $container = $allocate();
    $allocateMs = (\hrtime(true) - $start) / 1e6;

    $start = \hrtime(true);
    for ($i = 0; $i < $blocks; $i++) {
        $write($container, $i);
    }
    $writeMs = (\hrtime(true) - $start) / 1e6;

    $start = \hrtime(true);
    $bytes = 0;
    for ($i = 0; $i < $blocks; $i++) {
        $bytes += \strlen($read($container, $i));
    }
    $readMs = (\hrtime(true) - $start) / 1e6;

    $full = $reporter->diff($before, $reporter->snapshot());

    $start = \hrtime(true);
    $release($container);
    unset($container);
    \gc_collect_cycles();
    $releaseMs = (\hrtime(true) - $start) / 1e6;

    $after = $reporter->diff($before, $reporter->snapshot());

    \fwrite(STDOUT, \sprintf(
        "%-14s | %7.2f %7.2f %7.2f %7.2f | %10s %10s | %10s %10s\n",
        $name,
        $allocateMs,
        $writeMs,
        $readMs,
        $releaseMs,
        ByteFormatter::formatSigned($full->phpUsage),
        $full->rss === null ? 'n/a' : ByteFormatter::formatSigned($full->rss),
        ByteFormatter::formatSigned($after->phpUsage),
        $after->rss === null ? 'n/a' : ByteFormatter::formatSigned($after->rss),
    ));
}

\fwrite(STDOUT, \sprintf(
    "\n%s in %d blocks of %s.\n\n",
    ByteFormatter::format($total),
    $blocks,
    ByteFormatter::format($block),
));
\fwrite(STDOUT, \sprintf(
    "%-14s | %s | %s | %s\n",
    'container',
    '  alloc   write    read release  (ms)',
    '     while held (PHP / RSS)',
    '   after release (PHP / RSS)',
));
\fwrite(STDOUT, \str_repeat('-', 106) . "\n");

measure(
    'PHP string',
    $blocks,
    static fn (): array => ['data' => \str_repeat("\0", $GLOBALS['total'])],
    static function (array &$box, int $i) use ($block, $chunk): void {
        // A string is immutable in place only for reallocation purposes -
        // an overwrite of the same length happens in the existing buffer.
        $box['data'] = \substr_replace($box['data'], $chunk, $i * $block, $block);
    },
    static fn (array $box, int $i): string => \substr($box['data'], $i * $block, $block),
    static function (array &$box): void { $box['data'] = ''; },
);

measure(
    'PHP array',
    $blocks,
    static fn (): array => ['data' => []],
    static function (array &$box, int $i) use ($block, $source): void {
        $box['data'][$i] = \substr($source, $i * $block, $block);
    },
    static fn (array $box, int $i): string => $box['data'][$i],
    static function (array &$box): void { $box['data'] = []; },
);

measure(
    'FFI buffer',
    $blocks,
    static fn (): FfiBuffer => FfiBuffer::allocate($total),
    static function (FfiBuffer $buffer, int $i) use ($block, $chunk): void { $buffer->write($i * $block, $chunk); },
    static fn (FfiBuffer $buffer, int $i): string => $buffer->read($i * $block, $block),
    static function (FfiBuffer $buffer): void { $buffer->free(); },
);

measure(
    'mapped file',
    $blocks,
    static fn (): MappedFile => MappedFile::open($path, $total),
    static function (MappedFile $file, int $i) use ($block, $chunk): void { $file->write($i * $block, $chunk); },
    static fn (MappedFile $file, int $i): string => $file->read($i * $block, $block),
    static function (MappedFile $file): void { $file->unmap(); },
);

@\unlink($path);

experiment_note('only the two PHP containers appear in PHP usage. The other two are the same 16.00 MiB and the engine has no idea they exist - which is what makes RSS the only column that tells the truth about all four.');
experiment_note('the PHP string is slow to write for a structural reason, not an incidental one: PHP has no in-place block write into a string, so every partial update copies all 16.00 MiB. The array avoids that by never having one big buffer to copy - which also means it never has one contiguous region, and cannot be handed to anything that expects one.');
experiment_note('release tells the last part of the story. free() and munmap() return the memory to the OS immediately; the PHP containers return it to the allocator, which keeps the arenas - the PHP column goes back to zero and the RSS column does not.');
experiment_context();
