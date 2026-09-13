<?php

declare(strict_types=1);

namespace App\Experiment;

/**
 * A finished run, once the prose has been printed: which experiment, under
 * which options, and the numbers it chose to name.
 */
final readonly class ExperimentResult
{
    /**
     * @param array<string, int>                       $options
     * @param array<string, int|float|string|bool|null> $measurements
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $options,
        public array $measurements,
        public float $elapsedSeconds,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'experiment' => $this->name,
            'description' => $this->description,
            'options' => (object) $this->options,
            'measurements' => (object) $this->measurements,
            'elapsed_seconds' => $this->elapsedSeconds,
        ];
    }
}
