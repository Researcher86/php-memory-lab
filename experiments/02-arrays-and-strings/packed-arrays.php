#!/usr/bin/env php
<?php

declare(strict_types=1);

require \dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../run-helpers.php';

experiment_start('packed arrays: range vs append vs array_fill');

$reporter = experiment_reporter();

/*
 * Packed array = integer keys 0..n-1, stored as a flat C array of zvals.
 * The fastest, smallest array layout. The three constructions below all end
 * up with the same packed array; the question is whether the road to it
 * costs extra memory along the way (and how much is retained after unset).
 */
$N = 1_000_000;

$run = static function (string $label, callable $allocate) use ($reporter): void {
    $before = $reporter->snapshot();

    $value = $allocate();

    $after = $reporter->snapshot();
    experiment_snapshot($label, $after);
    experiment_delta('allocated', $reporter->diff($before, $after));

    unset($value);
};

$run(
    'range(1, N)',
    static fn (): array => \range(1, $N),
);

$run(
    'append in loop ($a[] = $i)',
    static function () use ($N): array {
        $a = [];
        for ($i = 1; $i <= $N; ++$i) {
            $a[] = $i;
        }

        return $a;
    },
);

$run(
    'array_fill(0, N, 0)',
    static fn (): array => \array_fill(0, $N, 0),
);

experiment_note('all three hold N integer zvals in a packed array; the loop builds it element by element, range preallocates.');
experiment_context();