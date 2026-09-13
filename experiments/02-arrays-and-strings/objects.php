#!/usr/bin/env php
<?php

declare(strict_types=1);

require \dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../run-helpers.php';

experiment_start('objects: object overhead versus associative arrays');

class ExperimentRow
{
    public function __construct(
        public int $a,
        public string $b,
        public float $c,
        public bool $d,
    ) {
    }
}

$reporter = experiment_reporter();
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
    'N assoc arrays  a,b,c,d',
    static function () use ($N): array {
        $rows = [];
        for ($i = 0; $i < $N; ++$i) {
            $rows[] = ['a' => $i, 'b' => 'x', 'c' => 1.5, 'd' => true];
        }

        return $rows;
    },
);

$run(
    'N DTO objects  a,b,c,d',
    static function () use ($N): array {
        $rows = [];
        for ($i = 0; $i < $N; ++$i) {
            $rows[] = new ExperimentRow($i, 'x', 1.5, true);
        }

        return $rows;
    },
);

$run(
    'N empty stdClass',
    static function () use ($N): array {
        $rows = [];
        for ($i = 0; $i < $N; ++$i) {
            $rows[] = new \stdClass();
        }

        return $rows;
    },
);

$run(
    'N empty arrays []',
    static function () use ($N): array {
        $rows = [];
        for ($i = 0; $i < $N; ++$i) {
            $rows[] = [];
        }

        return $rows;
    },
);

experiment_note('an object pays a per-instance header (handlers table, class ptr, properties hashtable); a typed DTO reuses a property table and can beat a plain assoc array of arrays.');
experiment_context();