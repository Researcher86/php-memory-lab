<?php

declare(strict_types=1);

namespace App\Tests\Experiment;

use App\Experiment\Output;
use App\Memory\MemoryDiff;
use App\Memory\MemorySnapshot;
use PHPUnit\Framework\TestCase;

final class OutputTest extends TestCase
{
    public function testProseGoesToTheStream(): void
    {
        [$output, $stream] = $this->recorder();

        $output->line('first');
        $output->printf("%s = %d\n", 'answer', 42);

        self::assertSame("first\nanswer = 42\n", $this->contents($stream));
    }

    public function testAQuietOutputStillCollectsMeasurements(): void
    {
        [$output, $stream] = $this->recorder(quiet: true);

        $output->line('invisible');
        $output->measure('rss_bytes', 4096);

        self::assertSame('', $this->contents($stream));
        self::assertSame(['rss_bytes' => 4096], $output->measurements());
    }

    public function testAMissingOsFieldReadsAsUnavailableRatherThanZero(): void
    {
        [$output, $stream] = $this->recorder();

        $output->snapshot('Before', new MemorySnapshot(
            phpUsage: 2_097_152,
            phpPeakUsage: 2_097_152,
            phpRealUsage: 2_097_152,
            phpRealPeakUsage: 2_097_152,
        ));

        $contents = $this->contents($stream);

        self::assertStringContainsString('PHP usage: 2.00 MiB', $contents);
        self::assertStringContainsString('RSS:       n/a', $contents);
    }

    public function testADeltaIsPrintedWithItsSign(): void
    {
        [$output, $stream] = $this->recorder();

        $output->delta('allocation', new MemoryDiff(
            phpUsage: 1_048_576,
            phpPeakUsage: 0,
            phpRealUsage: 0,
            phpRealPeakUsage: 0,
            rss: -4_096,
        ));

        $contents = $this->contents($stream);

        self::assertStringContainsString('PHP usage: +1.00 MiB', $contents);
        self::assertStringContainsString('RSS:       -4.00 KiB', $contents);
    }

    public function testTheSameNameMeasuredTwiceKeepsTheLastValue(): void
    {
        [$output] = $this->recorder(quiet: true);

        $output->measure('rounds', 1);
        $output->measure('rounds', 2);

        self::assertSame(['rounds' => 2], $output->measurements());
    }

    /**
     * @return array{Output, resource}
     */
    private function recorder(bool $quiet = false): array
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        return [new Output($stream, $quiet), $stream];
    }

    /**
     * @param resource $stream
     */
    private function contents($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
