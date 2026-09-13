<?php

declare(strict_types=1);

namespace App\Benchmark;

use Closure;

/**
 * One named thing to measure, with the iteration count that makes it
 * measurable.
 *
 * The count belongs to the benchmark rather than to the run because it is a
 * property of the operation: a socket round trip needs thousands of
 * iterations to rise above timer noise, and allocating a million-element
 * array needs one. A single global `--iterations` would make one of those two
 * meaningless.
 */
final readonly class Benchmark
{
    public function __construct(
        public string $name,
        public int $iterations,
        public Closure $run,
    ) {}
}
