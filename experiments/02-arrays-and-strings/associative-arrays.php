#!/usr/bin/env php
<?php

declare(strict_types=1);

require \dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../run-helpers.php';

experiment_start('associative arrays: string keys vs dense numeric keys');

$reporter = experiment_reporter();

/*
 * A packed array stores zvals flat and knows the key from the slot index.
 * Associative arrays (hash tables) store a hash bucket, key and entry per
 * element. String keys are always "associative"; densely-but-sparsely added
 * integer keys turn a packed array into a hash table too.
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
    'string keys  "key_$i" => $i',
    static function () use ($N): array {
        $a = [];
        for ($i = 0; $i < $N; ++$i) {
            $a['key_' . $i] = $i;
        }

        return $a;
    },
);

$run(
    'dense integer keys  0..N-1',
    static function () use ($N): array {
        $a = [];
        for ($i = 0; $i < $N; ++$i) {
            $a[$i] = $i;
        }

        return $a;
    },
);

$run(
    'sparse integer keys $i*10',
    static function () use ($N): array {
        $a = [];
        for ($i = 0; $i < $N; ++$i) {
            $a[$i * 10] = $i;
        }

        return $a;
    },
);

experiment_note('same element count; the string-keyed and sparse arrays pay for hash buckets and stored keys, the packed one does not.');
experiment_context();