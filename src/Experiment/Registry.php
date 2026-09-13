<?php

declare(strict_types=1);

namespace App\Experiment;

use RuntimeException;

/**
 * Finds the experiments on disk and hands them back by name.
 *
 * Discovery rather than a hard-coded list, because the list was hard-coded
 * for five phases and drifted from the directory twice. Each file under
 * `experiments/NN-topic/` returns an `Experiment` and declares its own name,
 * so a file that is renamed or moved between topics changes nothing for a
 * caller.
 *
 * Requiring all of them to build the index is cheap precisely because they
 * are descriptors: a file does nothing but construct an object and return it.
 * Everything that allocates - a segment, a mapping, a forked child - lives
 * inside the closure and happens only when that experiment is the one being
 * run.
 */
final class Registry
{
    /** @var array<string, Experiment>|null */
    private ?array $experiments = null;

    public function __construct(private readonly string $directory)
    {
    }

    /**
     * @return array<string, Experiment>
     */
    public function all(): array
    {
        if ($this->experiments !== null) {
            return $this->experiments;
        }

        $experiments = [];

        foreach (glob($this->directory . '/*/*.php') ?: [] as $path) {
            $experiment = require $path;

            if (!$experiment instanceof Experiment) {
                throw new RuntimeException(sprintf('%s did not return an Experiment', $path));
            }

            if (isset($experiments[$experiment->name])) {
                throw new RuntimeException(sprintf('Two experiments are called %s', $experiment->name));
            }

            $experiments[$experiment->name] = $experiment;
        }

        ksort($experiments);

        return $this->experiments = $experiments;
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    public function get(string $name): Experiment
    {
        return $this->all()[$name] ?? throw new RuntimeException(sprintf('Unknown experiment: %s', $name));
    }
}
