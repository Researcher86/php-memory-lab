<?php

declare(strict_types=1);

use App\Benchmark\Benchmark;
use App\Native\FfiBuffer;
use App\Native\MappedFile;

/**
 * The containers of phases 8 and 9 against the PHP ones, on identical work:
 * writing and reading one 64 KiB block into a 16 MiB region.
 *
 * The PHP string row is the interesting one, and it is slow for a structural
 * reason rather than an incidental one - PHP has no in-place block write into
 * a string, so a partial update copies the whole thing. See
 * docs/ffi-memory.md.
 *
 * @return list<Benchmark>
 */
$total = 16 * 1024 * 1024;
$block = 64 * 1024;
$chunk = str_repeat('x', $block);
$path = sys_get_temp_dir() . '/benchmark-native-' . bin2hex(random_bytes(4)) . '.bin';

$buffer = FfiBuffer::allocate($total);
$mapping = MappedFile::open($path, $total);
$string = str_repeat("\0", $total);
$offset = 8 * 1024 * 1024;

register_shutdown_function(static function () use ($buffer, $mapping, $path): void {
    $buffer->free();
    $mapping->unmap();
    @unlink($path);
});

return [
    new Benchmark('FFI buffer: write 64 KiB', 20_000, static function () use ($buffer, $offset, $chunk): void {
        $buffer->write($offset, $chunk);
    }),

    new Benchmark('FFI buffer: read 64 KiB', 20_000, static function () use ($buffer, $offset, $block): void {
        $buffer->read($offset, $block);
    }),

    new Benchmark('mapped file: write 64 KiB', 20_000, static function () use ($mapping, $offset, $chunk): void {
        $mapping->write($offset, $chunk);
    }),

    new Benchmark('mapped file: read 64 KiB', 20_000, static function () use ($mapping, $offset, $block): void {
        $mapping->read($offset, $block);
    }),

    new Benchmark('PHP string: read 64 KiB', 20_000, static function () use ($string, $offset, $block): void {
        substr($string, $offset, $block);
    }),

    new Benchmark('PHP string: write 64 KiB', 200, static function () use ($string, $offset, $chunk): void {
        substr_replace($string, $chunk, $offset, strlen($chunk));
    }),

    new Benchmark('mapped file: msync 16 MiB', 200, static function () use ($mapping): void {
        $mapping->flush();
    }),
];
