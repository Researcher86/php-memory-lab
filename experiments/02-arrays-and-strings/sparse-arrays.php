#!/usr/bin/env php
<?php

declare(strict_types=1);

require \dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../run-helpers.php';

experiment_start('sparse arrays: gaps turn packed into hash table');

$reporter = experiment_reporter();

/*
 * Dense integer keys 0..N-1 => packed array. As soon as a gap appears the
 * engine has no continuous slot array to use and switches the layout to a
 * hash table. Two "equal-looking" arrays can therefore differ a lot in
 * memory.
 */
$N = 100_000;

$run = static function (string $label, callable $allocate) use ($reporter): void {
    $before = $reporter->snapshot();

    $value = $allocate();

    $after = $reporter->snapshot();
    experiment_snapshot($label, $after);
    experiment_delta('allocated', $reporter->diff($before, $after));

    unset($value);
};

$run(
    'dense   keys 0..N-1',
    static function () use ($N): array {
        $a = [];
        for ($i = 0; $i < $N; ++$i) {
            $a[$i] = $i;
        }

        return $a;
    },
);

$run(
    'holes  keys 0,2,4,...',
    static function () use ($N): array {
        $a = [];
        for ($i = 0; $i < $N; ++$i) {
            $a[$i * 2] = $i;
        }

        return $a;
    },
);

$run(
    'built dense, then one big gap added',
    static function () use ($N): array {
        $a = [];
        for ($i = 0; $i < $N; ++$i) {
            $a[$i] = $i;
        }
        $a[$N * 100] = 'far away';

        return $a;
    },
);

experiment_note('the gap one-way becomes hash layout; dumping back to dense keys does not automatically return to packed.');
experiment_context();