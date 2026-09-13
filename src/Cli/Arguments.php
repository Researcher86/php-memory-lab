<?php

declare(strict_types=1);

namespace App\Cli;

use InvalidArgumentException;

/**
 * One command name and a bag of `--name=value` options, parsed once.
 *
 * Both entry points in `bin/` take the same shape - a subject followed by
 * options - and had grown their own loop, their own idea of what a bad value
 * was, and their own `exit(1)`. This is that loop, with the validation
 * expressed as exceptions so the caller decides how to fail rather than
 * having the decision made three frames down.
 *
 * Flags without values are deliberately unsupported. Everything this project
 * accepts is a positive integer or a word, and `--verbose` with no argument
 * would be the only exception - which is why there isn't one.
 */
final readonly class Arguments
{
    /**
     * @param array<string, string> $options
     */
    private function __construct(
        public ?string $command,
        private array $options,
    ) {
    }

    /**
     * @param list<string> $arguments usually array_slice($argv, 1)
     *
     * @throws InvalidArgumentException on an unparseable argument or a second
     *                                  command name
     */
    public static function parse(array $arguments): self
    {
        $command = null;
        $options = [];

        foreach ($arguments as $argument) {
            if (preg_match('/^--([a-z][a-z-]*)=(.*)$/', $argument, $matches) === 1) {
                $options[$matches[1]] = $matches[2];

                continue;
            }

            if ($command === null && !str_starts_with($argument, '-')) {
                $command = $argument;

                continue;
            }

            throw new InvalidArgumentException(sprintf('Unrecognised argument: %s', $argument));
        }

        return new self($command, $options);
    }

    public function string(string $name, string $default): string
    {
        return $this->options[$name] ?? $default;
    }

    public function has(string $name): bool
    {
        return isset($this->options[$name]);
    }

    /**
     * @throws InvalidArgumentException when the value is not a positive integer
     */
    public function positiveInt(string $name, int $default): int
    {
        if (!isset($this->options[$name])) {
            return $default;
        }

        $value = $this->options[$name];

        if (preg_match('/^\d+$/', $value) !== 1 || (int) $value < 1) {
            throw new InvalidArgumentException(sprintf('--%s must be a positive integer, got "%s"', $name, $value));
        }

        return (int) $value;
    }

    /**
     * Everything except the named options - which is how an entry point keeps
     * the few options that are its own and passes the rest along untouched.
     *
     * @return array<string, string>
     */
    public function except(string ...$names): array
    {
        return array_diff_key($this->options, array_flip($names));
    }
}
