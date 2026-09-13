<?php

declare(strict_types=1);

use App\Experiment\Experiment;
use App\Experiment\Options;
use App\Experiment\Output;
use App\Memory\MemoryReporter;

return new Experiment(
    name: 'memory:objects',
    description: 'object overhead against the equivalent associative array',
    supports: ['elements'],
    run: static function (Options $options, Output $out): void {
        $out->heading('objects: object overhead versus associative arrays');

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

        $reporter = new MemoryReporter();
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
            'N assoc arrays  a,b,c,d',
            static function () use ($N, $out): array {
                $rows = [];
                for ($i = 0; $i < $N; ++$i) {
                    $rows[] = ['a' => $i, 'b' => 'x', 'c' => 1.5, 'd' => true];
                }

                return $rows;
            },
        );

        $run(
            'N DTO objects  a,b,c,d',
            static function () use ($N, $out): array {
                $rows = [];
                for ($i = 0; $i < $N; ++$i) {
                    $rows[] = new ExperimentRow($i, 'x', 1.5, true);
                }

                return $rows;
            },
        );

        $run(
            'N empty stdClass',
            static function () use ($N, $out): array {
                $rows = [];
                for ($i = 0; $i < $N; ++$i) {
                    $rows[] = new \stdClass();
                }

                return $rows;
            },
        );

        $run(
            'N empty arrays []',
            static function () use ($N, $out): array {
                $rows = [];
                for ($i = 0; $i < $N; ++$i) {
                    $rows[] = [];
                }

                return $rows;
            },
        );

        $out->note('an object pays a per-instance header (handlers table, class ptr, properties hashtable); a typed DTO reuses a property table and can beat a plain assoc array of arrays.');
        $out->context();
    },
);
