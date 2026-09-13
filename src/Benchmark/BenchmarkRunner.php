<?php

declare(strict_types=1);

namespace App\Benchmark;

use App\Memory\MemoryReporter;

/**
 * Runs a callable often enough, and often enough times, to say something
 * about it.
 *
 * Two loops, and the difference between them is the whole design. The inner
 * one runs the operation `$iterations` times and is what gets timed; the
 * outer one repeats that whole measurement `$repetitions` times so there is a
 * distribution rather than a single number. A benchmark that reports one
 * timing cannot tell a fast operation from a lucky one.
 *
 * Warm-up repetitions run first and are thrown away. They exist because the
 * first run of anything in this lab is measuring something else: OPcache
 * compiling the closure, the allocator growing an arena, the kernel faulting
 * in pages, a socket buffer being sized.
 */
final readonly class BenchmarkRunner
{
    public function __construct(
        private MemoryReporter $reporter = new MemoryReporter(),
        private int $repetitions = 5,
        private int $warmups = 1,
    ) {}

    /**
     * @param callable(): mixed $operation
     */
    public function run(string $name, callable $operation, int $iterations = 1): BenchmarkResult
    {
        if ($iterations < 1) {
            throw new \InvalidArgumentException(\sprintf('%s: iterations must be at least 1', $name));
        }

        for ($warmup = 0; $warmup < $this->warmups; $warmup++) {
            $this->measureOnce($operation, $iterations);
        }

        // Cycles collected before the baseline, not during: a collection that
        // happens to fall inside a timed repetition is charged to whatever
        // was running at the time.
        gc_collect_cycles();
        $before = $this->reporter->snapshot();

        $samples = [];

        for ($repetition = 0; $repetition < $this->repetitions; $repetition++) {
            $samples[] = $this->measureOnce($operation, $iterations);
        }

        $delta = $this->reporter->diff($before, $this->reporter->snapshot());

        /** @var non-empty-list<float> $samples */
        return new BenchmarkResult(
            name: $name,
            iterations: $iterations,
            repetitions: $this->repetitions,
            elapsedSeconds: array_sum($samples),
            timings: Timings::of($samples),
            phpDelta: $delta->phpUsage,
            rssDelta: $delta->rss,
        );
    }

    /**
     * @param callable(): mixed $operation
     *
     * @return float seconds for one repetition
     */
    private function measureOnce(callable $operation, int $iterations): float
    {
        $start = hrtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $operation();
        }

        return (hrtime(true) - $start) / 1e9;
    }
}
