<?php

declare(strict_types=1);

namespace App\Tests\Memory;

use App\Memory\MemoryReporter;
use App\Memory\MemorySnapshot;
use PHPUnit\Framework\TestCase;

final class MemoryReporterTest extends TestCase
{
    public function testDiffComputesPositiveDeltas(): void
    {
        $before = new MemorySnapshot(
            phpUsage: 100,
            phpPeakUsage: 200,
            phpRealUsage: 300,
            phpRealPeakUsage: 400,
            rss: 1000,
            virtualMemory: 2000,
            sharedMemory: 300,
            privateMemory: 700,
            pss: 600,
        );

        $after = new MemorySnapshot(
            phpUsage: 150,
            phpPeakUsage: 240,
            phpRealUsage: 420,
            phpRealPeakUsage: 500,
            rss: 1800,
            virtualMemory: 3200,
            sharedMemory: 400,
            privateMemory: 1400,
            pss: 900,
        );

        $diff = new MemoryReporter()->diff($before, $after);

        self::assertSame(50, $diff->phpUsage);
        self::assertSame(40, $diff->phpPeakUsage);
        self::assertSame(120, $diff->phpRealUsage);
        self::assertSame(100, $diff->phpRealPeakUsage);
        self::assertSame(800, $diff->rss);
        self::assertSame(1200, $diff->virtualMemory);
        self::assertSame(100, $diff->sharedMemory);
        self::assertSame(700, $diff->privateMemory);
        self::assertSame(300, $diff->pss);
    }

    public function testDiffComputesNegativeDeltas(): void
    {
        $before = new MemorySnapshot(
            phpUsage: 200,
            phpPeakUsage: 300,
            phpRealUsage: 400,
            phpRealPeakUsage: 500,
            rss: 2000,
            virtualMemory: 3000,
            sharedMemory: 500,
            privateMemory: 1500,
            pss: 1000,
        );

        $after = new MemorySnapshot(
            phpUsage: 100,
            phpPeakUsage: 300,
            phpRealUsage: 350,
            phpRealPeakUsage: 500,
            rss: 1200,
            virtualMemory: 2800,
            sharedMemory: 400,
            privateMemory: 800,
            pss: 600,
        );

        $diff = new MemoryReporter()->diff($before, $after);

        self::assertSame(-100, $diff->phpUsage);
        self::assertSame(0, $diff->phpPeakUsage);
        self::assertSame(-50, $diff->phpRealUsage);
        self::assertSame(0, $diff->phpRealPeakUsage);
        self::assertSame(-800, $diff->rss);
        self::assertSame(-200, $diff->virtualMemory);
        self::assertSame(-100, $diff->sharedMemory);
        self::assertSame(-700, $diff->privateMemory);
        self::assertSame(-400, $diff->pss);
    }

    public function testDiffReturnsNullWhenEitherSideHasNullOsField(): void
    {
        $withOs = new MemorySnapshot(
            phpUsage: 100,
            phpPeakUsage: 200,
            phpRealUsage: 300,
            phpRealPeakUsage: 400,
            rss: 1000,
            privateMemory: 700,
            pss: 600,
        );

        $withoutOs = new MemorySnapshot(
            phpUsage: 100,
            phpPeakUsage: 200,
            phpRealUsage: 300,
            phpRealPeakUsage: 400,
        );

        $diff = new MemoryReporter()->diff($withOs, $withoutOs);

        self::assertSame(0, $diff->phpUsage);
        self::assertNull($diff->rss);
        self::assertNull($diff->privateMemory);
        self::assertNull($diff->pss);
    }

    public function testSnapshotReturnsLiveProcMetrics(): void
    {
        if (!is_readable('/proc/self/status') || !is_readable('/proc/self/smaps_rollup')) {
            self::markTestSkipped('/proc is not available on this host');
        }

        $snapshot = new MemoryReporter()->snapshot();

        self::assertGreaterThan(0, $snapshot->phpUsage);
        self::assertGreaterThan(0, $snapshot->phpRealUsage);
        self::assertGreaterThan(0, $snapshot->rss);
        self::assertGreaterThan(0, $snapshot->pss);
        self::assertNotNull($snapshot->virtualMemory);
        self::assertNotNull($snapshot->privateMemory);
    }
}
