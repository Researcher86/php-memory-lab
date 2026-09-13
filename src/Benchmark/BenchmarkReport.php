<?php

declare(strict_types=1);

namespace App\Benchmark;

/**
 * A set of results plus the machine they came from. Nothing in this project
 * prints a benchmark without its environment, because the same code on
 * another CPU, another allocator or another cgroup limit produces different
 * numbers and both sets are correct.
 */
final readonly class BenchmarkReport
{
    /**
     * @param list<BenchmarkResult> $results
     */
    public function __construct(
        public string $suite,
        public Environment $environment,
        public array $results,
    ) {}
}
