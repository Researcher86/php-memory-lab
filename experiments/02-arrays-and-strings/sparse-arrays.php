<?php

declare(strict_types=1);

use App\Experiment\Experiment;
use App\Experiment\Options;
use App\Experiment\Output;
use App\Memory\MemoryReporter;

return new Experiment(
    name: 'memory:sparse-arrays',
    description: 'gaps in numeric keys, and the one-way switch to a hash table',
    supports: ['elements'],
    run: static function (Options $options, Output $out): void {
        $out->heading('sparse arrays: gaps turn packed into hash table');

        $reporter = new MemoryReporter();

        /*
         * Dense integer keys 0..N-1 => packed array. As soon as a gap appears the
         * engine has no continuous slot array to use and switches the layout to a
         * hash table. Two "equal-looking" arrays can therefore differ a lot in
         * memory.
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
            'dense   keys 0..N-1',
            static function () use ($N, $out): array {
                $a = [];
                for ($i = 0; $i < $N; ++$i) {
                    $a[$i] = $i;
                }

                return $a;
            },
        );

        $run(
            'holes  keys 0,2,4,...',
            static function () use ($N, $out): array {
                $a = [];
                for ($i = 0; $i < $N; ++$i) {
                    $a[$i * 2] = $i;
                }

                return $a;
            },
        );

        $run(
            'built dense, then one big gap added',
            static function () use ($N, $out): array {
                $a = [];
                for ($i = 0; $i < $N; ++$i) {
                    $a[$i] = $i;
                }
                $a[$N * 100] = 'far away';

                return $a;
            },
        );

        $out->note('the gap one-way becomes hash layout; dumping back to dense keys does not automatically return to packed.');
        $out->context();
    },
);
