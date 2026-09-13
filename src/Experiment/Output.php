<?php

declare(strict_types=1);

namespace App\Experiment;

use App\Memory\ByteFormatter;
use App\Memory\MemoryDiff;
use App\Memory\MemorySnapshot;
use App\Memory\SmapsRollup;

/**
 * Where an experiment writes, and what it records.
 *
 * Two jobs at once, on purpose. The prose goes to the stream as it is
 * produced - an experiment that forks children and then waits three seconds
 * should not look hung - while the numbers an experiment names through
 * measure() are collected, so the same run can also be reported as JSON.
 *
 * This replaces a file of global functions. A forked child writing through an
 * instance it inherited is visible in the code; a forked child writing
 * through a global is a thing to remember.
 */
final class Output
{
    /** @var array<string, int|float|string|bool|null> */
    private array $measurements = [];

    /**
     * @param resource $stream
     */
    public function __construct(
        private readonly mixed $stream = \STDOUT,
        private readonly bool $quiet = false,
    ) {}

    public function line(string $line = ''): void
    {
        $this->write($line . "\n");
    }

    public function write(string $text): void
    {
        if (!$this->quiet) {
            fwrite($this->stream, $text);
        }
    }

    /** printf into the stream, for tables built column by column. */
    public function printf(string $format, int|float|string ...$arguments): void
    {
        $this->write(\sprintf($format, ...$arguments));
    }

    public function heading(string $title): void
    {
        $this->line(\sprintf('Experiment: %s', $title));
        $this->line(\sprintf('PID: %d', (int) getmypid()));
    }

    /**
     * A number this experiment wants to be remembered by. These are what the
     * JSON output carries: the prose is for a reader, these are for a diff
     * against the previous run.
     */
    public function measure(string $name, int|float|string|bool|null $value): void
    {
        $this->measurements[$name] = $value;
    }

    public function snapshot(string $label, MemorySnapshot $snapshot): void
    {
        $this->line();
        $this->line($label . ':');
        $this->printf("  PHP usage: %s\n", ByteFormatter::format($snapshot->phpUsage));
        $this->printf("  RSS:       %s\n", self::bytes($snapshot->rss));
        $this->printf("  Private:   %s\n", self::bytes($snapshot->privateMemory));
    }

    public function delta(string $label, MemoryDiff $diff): void
    {
        $this->line();
        $this->line(\sprintf('Delta %s:', $label));
        $this->printf("  PHP usage: %s\n", ByteFormatter::formatSigned($diff->phpUsage));
        $this->printf("  RSS:       %s\n", self::signedBytes($diff->rss));
        $this->printf("  Private:   %s\n", self::signedBytes($diff->privateMemory));
    }

    public function smaps(string $label, SmapsRollup $rollup): void
    {
        $this->printf(
            "%s: RSS %s | PSS %s | Shared_Dirty %s | Private_Dirty %s\n",
            $label,
            ByteFormatter::format($rollup->rss),
            ByteFormatter::format($rollup->pss),
            ByteFormatter::format($rollup->sharedDirty),
            ByteFormatter::format($rollup->privateDirty),
        );
    }

    public function note(string $message): void
    {
        $this->line();
        $this->line('Note: ' . $message);
    }

    public function context(): void
    {
        $this->line();
        $this->line('Context: values depend on the PHP version, allocator, and container limits.');
    }

    /**
     * @return array<string, int|float|string|bool|null>
     */
    public function measurements(): array
    {
        return $this->measurements;
    }

    private static function bytes(?int $value): string
    {
        return $value === null ? 'n/a' : ByteFormatter::format($value);
    }

    private static function signedBytes(?int $value): string
    {
        return $value === null ? 'n/a' : ByteFormatter::formatSigned($value);
    }
}
