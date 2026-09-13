<?php

declare(strict_types=1);

namespace App\Experiment;

use InvalidArgumentException;

/**
 * Runs one experiment under one set of options and reports what came of it.
 *
 * The option check happens here rather than inside each experiment, so that
 * every experiment refuses an option it does not honour in the same words -
 * and so that none of them has to remember to.
 */
final readonly class ExperimentRunner
{
    public function __construct(
        private Registry $registry,
    ) {
    }

    public function run(string $name, Options $options, Output $output): ExperimentResult
    {
        $experiment = $this->registry->get($name);
        $unsupported = array_diff($options->names(), $experiment->supports);

        if ($unsupported !== []) {
            throw new InvalidArgumentException(sprintf(
                '%s does not use --%s. It understands: %s',
                $name,
                implode(', --', $unsupported),
                $experiment->supports === [] ? 'no options' : '--' . implode(', --', $experiment->supports),
            ));
        }

        $start = hrtime(true);
        ($experiment->run)($options, $output);

        return new ExperimentResult(
            name: $experiment->name,
            description: $experiment->description,
            options: $options->toArray(),
            measurements: $output->measurements(),
            elapsedSeconds: (hrtime(true) - $start) / 1e9,
        );
    }
}
