#!/usr/bin/env php
<?php

declare(strict_types=1);

require \dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../run-helpers.php';

experiment_start('nested arrays: rows and depth');

$reporter = experiment_reporter();

/*
 * A nested array is a tree of hash tables. Inner values are zvals holding
 * references to other arrays, so the same element repeated in several rows
 * can either share a zval (memory saved) or be copied fresh (memory spent).
 * This experiment measures the common "table" shape: many rows, each a
 * packed row, plus the reference-versus-copy choice.
 */
$ROWS = 10_000;
$COLS = 10;

$run = static function (string $label, callable $allocate) use ($reporter): void {
    $before = $reporter->snapshot();

    $value = $allocate();

    $after = $reporter->snapshot();
    experiment_snapshot($label, $after);
    experiment_delta('allocated', $reporter->diff($before, $after));

    unset($value);
};

$run(
    "table ROWS x COLS filled with range(COLS)",
    static function () use ($ROWS, $COLS): array {
        $table = [];
        for ($r = 0; $r < $ROWS; ++$r) {
            $table[] = \range(0, $COLS - 1);
        }

        return $table;
    },
);

$run(
    "same table, rows share one frozen row (reference)",
    static function () use ($ROWS, $COLS): array {
        $frozenRow = \range(0, $COLS - 1);
        $table = [];
        for ($r = 0; $r < $ROWS; ++$r) {
            $table[] = $frozenRow; // same zval referenced ROWS times
        }

        return $table;
    },
);

$run(
    "compact CSV-like version, one flat array of ints ROWS*COLS",
    static function () use ($ROWS, $COLS): array {
        return \range(0, $ROWS * $COLS - 1);
    },
);

experiment_note('row objects vs frozen reference vs flat: the flat packed array is the smallest; a matrix worth using stays flat when possible.');
experiment_context();