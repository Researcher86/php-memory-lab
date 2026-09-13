<?php

declare(strict_types=1);

namespace App\Experiment;

use InvalidArgumentException;

/**
 * The options an experiment was asked to run with.
 *
 * One vocabulary for every experiment, so that `--children=8` means the same
 * thing to a fork experiment and to a shared-memory one, and so an experiment
 * that does not understand an option says so instead of ignoring it. A
 * silently accepted option is how a run gets reported under a configuration
 * it never had.
 *
 * Values are read through the accessors below rather than out of an array,
 * because every one of them is a number with a floor: zero children is not a
 * smaller experiment, it is a different one.
 */
final readonly class Options
{
    public const KNOWN = [
        'size' => 'bytes for a buffer, mapping or payload',
        'elements' => 'elements in a generated data structure',
        'children' => 'child processes to fork',
        'iterations' => 'times to repeat the measured operation',
        'payload-size' => 'bytes per message',
        'sleep' => 'microseconds to pause between steps',
    ];

    private function __construct(
        /** @var array<string, int> */
        private array $values,
    ) {
    }

    /**
     * @param array<string, string> $raw option name => raw string value
     *
     * @throws InvalidArgumentException on an unknown name, or a value that is
     *                                  not a positive integer
     */
    public static function fromStrings(array $raw): self
    {
        $values = [];

        foreach ($raw as $name => $value) {
            if (!isset(self::KNOWN[$name])) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown option --%s. Known options: %s',
                    $name,
                    implode(', ', array_keys(self::KNOWN)),
                ));
            }

            if (preg_match('/^\d+$/', $value) !== 1 || (int) $value < 1) {
                throw new InvalidArgumentException(sprintf(
                    '--%s must be a positive integer, got "%s"',
                    $name,
                    $value,
                ));
            }

            $values[$name] = (int) $value;
        }

        return new self($values);
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function size(int $default): int
    {
        return $this->values['size'] ?? $default;
    }

    public function elements(int $default): int
    {
        return $this->values['elements'] ?? $default;
    }

    public function children(int $default): int
    {
        return $this->values['children'] ?? $default;
    }

    public function iterations(int $default): int
    {
        return $this->values['iterations'] ?? $default;
    }

    public function payloadSize(int $default): int
    {
        return $this->values['payload-size'] ?? $default;
    }

    public function sleep(int $default): int
    {
        return $this->values['sleep'] ?? $default;
    }

    /**
     * The names that were actually passed, so that an experiment can refuse
     * one it does not support.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->values);
    }

    /**
     * @return array<string, int> only what was passed, so a report shows the
     *                            run's configuration and not this class's
     *                            defaults
     */
    public function toArray(): array
    {
        return $this->values;
    }
}
