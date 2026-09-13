<?php

declare(strict_types=1);

use App\Experiment\Experiment;
use App\Experiment\Options;
use App\Experiment\Output;
use App\Memory\MemoryReporter;

return new Experiment(
    name: 'memory:strings',
    description: 'what strings cost, and where PHP-level copy-on-write ends',
    supports: [],
    run: static function (Options $options, Output $out): void {
        $out->heading('string cost: empty / short / fixed / grown / copied');

        $reporter = new MemoryReporter();
        $baseline = $reporter->snapshot();

        // The reporter itself, the constants, nothing has been grown on purpose yet.
        $out->note('baseline is the fixer + autoload + reporter for this script');

        /*
         * The scenario protocol: snapshot -> allocate -> snapshot -> unset -> snapshot.
         * The "cost" delta is between before and after allocation; the "cleanup" delta
         * shows whether the engine gave the bytes back (strings do - plain refcounting).
         * RSS mostly does not come back: the allocator keeps pages for reuse.
         */

        $run = static function (string $label, callable $allocate, ?callable $free = null) use ($reporter, $out): void {
            $before = $reporter->snapshot();

            $value = $allocate();

            $after = $reporter->snapshot();
            $out->snapshot($label, $after);
            $out->delta('allocated', $reporter->diff($before, $after));

            if ($free !== null) {
                $free($value);
            }

            $cleaned = $reporter->snapshot();
            $out->delta('after unset', $reporter->diff($after, $cleaned));
        };

        $free = static function ($value): void {
            unset($value);
        };

        $run('empty string        (strlen 0)', static fn (): string => '');
        $run('short string        (strlen 32)', static fn (): string => \str_repeat('a', 32));
        $run('1 KiB string', static fn (): string => \str_repeat('a', 1 << 10));
        $run('1 MiB string', static fn (): string => \str_repeat('a', 1 << 20));
        $run(
            'grown by .= 10k x 1k',
            static function (): string {
                $s = '';
                for ($i = 0; $i < 10_000; ++$i) {
                    $s .= \str_repeat('x', 1_024);
                }

                return $s;
            },
            $free,
        );

        /*
         * Copy-on-Write: `$b = $a` does not copy the string, it bumps a refcount.
         * The copy appears only when one of the two is actually modified. Measured
         * step by step, not as a single allocate-then-unset.
         */
        $base = \str_repeat('a', 1 << 20);
        $step = $reporter->snapshot();
        $b = $base;
        $afterCopy = $reporter->snapshot();
        $out->snapshot('after $b = $a (copy should be free)', $afterCopy);
        $out->delta('copy assignment', $reporter->diff($step, $afterCopy));

        $b[0] = 'z';
        $afterWrite = $reporter->snapshot();
        $out->snapshot('after $b[0] = "z" (separation)', $afterWrite);
        $out->delta('first write', $reporter->diff($afterCopy, $afterWrite));
        unset($base, $b);

        $out->context();
    },
);
