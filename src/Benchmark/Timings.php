<?php

declare(strict_types=1);

namespace App\Benchmark;

/**
 * The distribution of one benchmark's repetitions, in seconds.
 *
 * Percentiles are kept here where `php-worker-pool`'s DurationStat
 * deliberately drops them, and for the opposite reason: a benchmark holds a
 * handful of samples for a few seconds, not an unbounded stream for the life
 * of a process. The median matters because a benchmark's mean is routinely
 * dragged by one repetition that lost its CPU to something else, and the
 * gap between min and max is the honest way to say how much to trust either.
 */
final readonly class Timings
{
    private function __construct(
        public float $min,
        public float $max,
        public float $mean,
        public float $median,
        public float $p95,
    ) {}

    /**
     * @param non-empty-list<float> $samples seconds per repetition
     */
    public static function of(array $samples): self
    {
        sort($samples);
        $count = \count($samples);

        return new self(
            min: $samples[0],
            max: $samples[$count - 1],
            mean: array_sum($samples) / $count,
            median: self::percentile($samples, 0.5),
            p95: self::percentile($samples, 0.95),
        );
    }

    /**
     * Nearest-rank on an already sorted list. Not interpolated: with the
     * sample counts a benchmark run produces, interpolation invents precision
     * the measurement does not have.
     *
     * @param non-empty-list<float> $sorted
     */
    private static function percentile(array $sorted, float $fraction): float
    {
        $count = \count($sorted);

        if ($count === 1) {
            return $sorted[0];
        }

        $rank = (int) ceil($fraction * $count) - 1;

        return $sorted[max(0, min($count - 1, $rank))];
    }
}
