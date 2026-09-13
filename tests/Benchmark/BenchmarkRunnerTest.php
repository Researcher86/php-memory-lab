<?php

declare(strict_types=1);

namespace App\Tests\Benchmark;

use App\Benchmark\BenchmarkRunner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BenchmarkRunnerTest extends TestCase
{
    public function testTheOperationRunsIterationsTimesPerRepetition(): void
    {
        $calls = 0;
        $runner = new BenchmarkRunner(repetitions: 3, warmups: 0);

        $runner->run('counted', static function () use (&$calls): void {
            ++$calls;
        }, iterations: 5);

        self::assertSame(15, $calls);
    }

    /**
     * The first run of anything measures something else - OPcache compiling
     * the closure, the allocator growing an arena, pages being faulted in -
     * so warm-up repetitions run and are thrown away.
     */
    public function testWarmupRepetitionsRunButAreNotTimed(): void
    {
        $calls = 0;
        $runner = new BenchmarkRunner(repetitions: 2, warmups: 3);

        $result = $runner->run('warmed', static function () use (&$calls): void {
            ++$calls;
        }, iterations: 10);

        self::assertSame(50, $calls, '3 warm-up plus 2 timed repetitions of 10 iterations');
        self::assertSame(2, $result->repetitions, 'only the timed repetitions are reported');
    }

    public function testTheResultCarriesTheShapeOfTheRun(): void
    {
        $runner = new BenchmarkRunner(repetitions: 4, warmups: 1);

        $result = $runner->run('shape', static fn (): int => 1, iterations: 7);

        self::assertSame('shape', $result->name);
        self::assertSame(7, $result->iterations);
        self::assertSame(4, $result->repetitions);
        self::assertGreaterThan(0.0, $result->elapsedSeconds);
        self::assertGreaterThanOrEqual($result->timings->min, $result->timings->max);
    }

    public function testOperationsPerSecondComesFromTheMedianRepetition(): void
    {
        $runner = new BenchmarkRunner(repetitions: 3, warmups: 0);

        $result = $runner->run('rate', static function (): void {
            usleep(1_000);
        }, iterations: 10);

        // Ten sleeps of 1 ms each is ~100 operations per second, generously
        // bounded because a sleep is a lower bound and the scheduler decides
        // the rest.
        self::assertGreaterThan(10.0, $result->operationsPerSecond());
        self::assertLessThan(1_000.0, $result->operationsPerSecond());
        self::assertEqualsWithDelta(
            $result->timings->median / 10,
            $result->secondsPerOperation(),
            1e-9,
        );
    }

    public function testTheMemoryDeltaCoversTheWholeTimedRun(): void
    {
        $held = null;
        $runner = new BenchmarkRunner(repetitions: 1, warmups: 0);

        $result = $runner->run('allocating', static function () use (&$held): void {
            $held[] = str_repeat('x', 1024 * 1024);
        }, iterations: 4);

        self::assertGreaterThan(4 * 1024 * 1024 - 4096, $result->phpDelta);
    }

    public function testZeroIterationsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BenchmarkRunner()->run('nothing', static fn (): int => 1, iterations: 0);
    }
}
