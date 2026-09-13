<?php

declare(strict_types=1);

use App\Experiment\Experiment;
use App\Experiment\Options;
use App\Experiment\Output;
use App\Memory\MemoryReporter;

return new Experiment(
    name: 'memory:packed-arrays',
    description: 'range vs append vs array_fill for the same packed array',
    supports: ['elements'],
    run: static function (Options $options, Output $out): void {
        $out->heading('packed arrays: range vs append vs array_fill');

        $reporter = new MemoryReporter();

        /*
         * Packed array = integer keys 0..n-1, stored as a flat C array of zvals.
         * The fastest, smallest array layout. The three constructions below all end
         * up with the same packed array; the question is whether the road to it
         * costs extra memory along the way (and how much is retained after unset).
         */
        $N = $options->elements(1_000_000);

        $run = static function (string $label, callable $allocate) use ($reporter, $out): void {
            $before = $reporter->snapshot();

            $value = $allocate();

            $after = $reporter->snapshot();
            $out->snapshot($label, $after);
            $out->delta('allocated', $reporter->diff($before, $after));

            unset($value);
        };

        $run(
            'range(1, N)',
            static fn (): array => \range(1, $N),
        );

        $run(
            'append in loop ($a[] = $i)',
            static function () use ($N, $out): array {
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

        $out->note('all three hold N integer zvals in a packed array; the loop builds it element by element, range preallocates.');
        $out->context();
    },
);
