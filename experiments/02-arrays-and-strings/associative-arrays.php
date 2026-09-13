<?php

declare(strict_types=1);

use App\Experiment\Experiment;
use App\Experiment\Options;
use App\Experiment\Output;
use App\Memory\MemoryReporter;

return new Experiment(
    name: 'memory:associative-arrays',
    description: 'string keys against numeric ones, and what the hash table adds',
    supports: ['elements'],
    run: static function (Options $options, Output $out): void {
        $out->heading('associative arrays: string keys vs dense numeric keys');

        $reporter = new MemoryReporter();

        /*
         * A packed array stores zvals flat and knows the key from the slot index.
         * Associative arrays (hash tables) store a hash bucket, key and entry per
         * element. String keys are always "associative"; densely-but-sparsely added
         * integer keys turn a packed array into a hash table too.
         */
        $N = $options->elements(100_000);

        $run = static function (string $label, callable $allocate) use ($reporter, $out): void {
            $before = $reporter->snapshot();

            $value = $allocate();

            $after = $reporter->snapshot();
            $out->snapshot($label, $after);
            $out->delta('allocated', $reporter->diff($before, $after));

            unset($value);
        };

        $run(
            'string keys  "key_$i" => $i',
            static function () use ($N, $out): array {
                $a = [];
                for ($i = 0; $i < $N; ++$i) {
                    $a['key_' . $i] = $i;
                }

                return $a;
            },
        );

        $run(
            'dense integer keys  0..N-1',
            static function () use ($N, $out): array {
                $a = [];
                for ($i = 0; $i < $N; ++$i) {
                    $a[$i] = $i;
                }

                return $a;
            },
        );

        $run(
            'sparse integer keys $i*10',
            static function () use ($N, $out): array {
                $a = [];
                for ($i = 0; $i < $N; ++$i) {
                    $a[$i * 10] = $i;
                }

                return $a;
            },
        );

        $out->note('same element count; the string-keyed and sparse arrays pay for hash buckets and stored keys, the packed one does not.');
        $out->context();
    },
);
