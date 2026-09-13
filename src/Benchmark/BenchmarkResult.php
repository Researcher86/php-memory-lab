<?php

declare(strict_types=1);

namespace App\Benchmark;

/**
 * One benchmark, measured.
 *
 * Both memory views are carried, as everywhere else in this lab: `phpDelta`
 * is what the engine's allocator reports and `rssDelta` is what the OS does.
 * A benchmark of anything below the engine - shared memory, a mapping, an FFI
 * buffer - moves only the second one, and a result that reported just the
 * first would say the operation was free.
 */
final readonly class BenchmarkResult
{
    public function __construct(
        public string $name,
        /** Calls to the measured operation per repetition. */
        public int $iterations,
        /** Timed repetitions, after the warm-up ones were discarded. */
        public int $repetitions,
        /** Seconds across all timed repetitions. */
        public float $elapsedSeconds,
        /** Distribution of one repetition's duration. */
        public Timings $timings,
        public int $phpDelta,
        public ?int $rssDelta,
    ) {
    }

    /**
     * Based on the median repetition rather than the mean: one repetition
     * that lost its CPU to something else should not decide the headline
     * number.
     */
    public function operationsPerSecond(): float
    {
        return $this->timings->median <= 0.0 ? 0.0 : $this->iterations / $this->timings->median;
    }

    public function secondsPerOperation(): float
    {
        return $this->timings->median / $this->iterations;
    }
}
