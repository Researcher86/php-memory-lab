<?php

declare(strict_types=1);

namespace App\Tests\Memory;

use App\Memory\MemorySnapshot;
use PHPUnit\Framework\TestCase;

final class MemorySnapshotTest extends TestCase
{
    public function testDefaultsAreNullForOsFields(): void
    {
        $snapshot = new MemorySnapshot(
            phpUsage: 100,
            phpPeakUsage: 200,
            phpRealUsage: 300,
            phpRealPeakUsage: 400,
        );

        self::assertSame(100, $snapshot->phpUsage);
        self::assertSame(200, $snapshot->phpPeakUsage);
        self::assertSame(300, $snapshot->phpRealUsage);
        self::assertSame(400, $snapshot->phpRealPeakUsage);
        self::assertNull($snapshot->rss);
        self::assertNull($snapshot->virtualMemory);
        self::assertNull($snapshot->sharedMemory);
        self::assertNull($snapshot->privateMemory);
        self::assertNull($snapshot->pss);
    }

    public function testConstructorStoresEveryField(): void
    {
        $snapshot = new MemorySnapshot(
            phpUsage: 100,
            phpPeakUsage: 200,
            phpRealUsage: 300,
            phpRealPeakUsage: 400,
            rss: 500,
            virtualMemory: 600,
            sharedMemory: 700,
            privateMemory: 800,
            pss: 900,
        );

        self::assertSame(500, $snapshot->rss);
        self::assertSame(600, $snapshot->virtualMemory);
        self::assertSame(700, $snapshot->sharedMemory);
        self::assertSame(800, $snapshot->privateMemory);
        self::assertSame(900, $snapshot->pss);
    }
}
