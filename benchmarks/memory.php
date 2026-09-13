<?php

declare(strict_types=1);

use App\Benchmark\Benchmark;

/**
 * What the PHP containers of Phase 2 cost, measured the same way as
 * everything else in this project rather than by eye.
 *
 * @return list<Benchmark>
 */
return [
    new Benchmark('string: str_repeat 1 MiB', 200, static function (): void {
        $string = str_repeat('x', 1024 * 1024);
        unset($string);
    }),

    new Benchmark('string: concat 1k x 1 KiB', 200, static function (): void {
        $string = '';

        for ($i = 0; $i < 1_000; $i++) {
            $string .= str_repeat('x', 1024);
        }
    }),

    new Benchmark('array: range(1, 100k)', 200, static function (): void {
        $array = range(1, 100_000);
        unset($array);
    }),

    new Benchmark('array: 100k appends', 50, static function (): void {
        $array = [];

        for ($i = 0; $i < 100_000; $i++) {
            $array[] = $i;
        }
    }),

    new Benchmark('array: 100k string keys', 50, static function (): void {
        $array = [];

        for ($i = 0; $i < 100_000; $i++) {
            $array['key-' . $i] = $i;
        }
    }),

    new Benchmark('objects: 100k stdClass', 20, static function (): void {
        $objects = [];

        for ($i = 0; $i < 100_000; $i++) {
            $object = new stdClass();
            $object->id = $i;
            $objects[] = $object;
        }
    }),

    new Benchmark('gc: collect 10k cycles', 20, static function (): void {
        for ($i = 0; $i < 10_000; $i++) {
            $a = new stdClass();
            $b = new stdClass();
            $a->peer = $b;
            $b->peer = $a;
            unset($a, $b);
        }

        gc_collect_cycles();
    }),
];
