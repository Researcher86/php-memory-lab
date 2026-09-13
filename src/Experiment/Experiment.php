<?php

declare(strict_types=1);

namespace App\Experiment;

use Closure;

/**
 * One runnable experiment: what it is called, what it demonstrates, which
 * options it honours, and the code that does it.
 *
 * A descriptor rather than a base class to subclass, for the same reason
 * `Benchmark` is one. An experiment is a script with a narrative - its value
 * is in being readable top to bottom - and an abstract parent would scatter
 * that narrative across a constructor, a template method and three hooks.
 *
 * `$supports` lists the options the closure actually reads. Anything else
 * passed on the command line is refused rather than ignored.
 */
final readonly class Experiment
{
    /**
     * @param list<string>                  $supports
     * @param Closure(Options, Output): void $run
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $supports,
        public Closure $run,
    ) {}
}
