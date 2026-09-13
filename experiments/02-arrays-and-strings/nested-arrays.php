<?php

declare(strict_types=1);

use App\Experiment\Experiment;
use App\Experiment\Options;
use App\Experiment\Output;
use App\Memory\MemoryReporter;

return new Experiment(
    name: 'memory:nested-arrays',
    description: 'rows, depth and references in nested arrays',
    supports: [],
    run: static function (Options $options, Output $out): void {
        $out->heading('nested arrays: rows and depth');

        $reporter = new MemoryReporter();

        /*
         * A nested array is a tree of hash tables. Inner values are zvals holding
         * references to other arrays, so the same element repeated in several rows
         * can either share a zval (memory saved) or be copied fresh (memory spent).
         * This experiment measures the common "table" shape: many rows, each a
         * packed row, plus the reference-versus-copy choice.
         */
        $ROWS = 10_000;
        $COLS = 10;

        $run = static function (string $label, callable $allocate) use ($reporter, $out): void {
            $before = $reporter->snapshot();

            $value = $allocate();

            $after = $reporter->snapshot();
            $out->snapshot($label, $after);
            $out->delta('allocated', $reporter->diff($before, $after));

            unset($value);
        };

        $run(
            "table ROWS x COLS filled with range(COLS)",
            static function () use ($ROWS, $COLS, $out): array {
                $table = [];
                for ($r = 0; $r < $ROWS; ++$r) {
                    $table[] = \range(0, $COLS - 1);
                }

                return $table;
            },
        );

        $run(
            "same table, rows share one frozen row (reference)",
            static function () use ($ROWS, $COLS, $out): array {
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
            static function () use ($ROWS, $COLS, $out): array {
                return \range(0, $ROWS * $COLS - 1);
            },
        );

        $out->note('row objects vs frozen reference vs flat: the flat packed array is the smallest; a matrix worth using stays flat when possible.');
        $out->context();
    },
);
