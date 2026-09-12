<?php

declare(strict_types=1);

namespace App\Tests\Memory;

use App\Memory\SmapsRollup;
use App\Memory\SmapsRollupReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SmapsRollupReaderTest extends TestCase
{
    private const SMAPS_ROLLUP = <<<'TXT'
        Size:               58680 kB
        KernelPageSize:        4 kB
        MMUPageSize:            4 kB
        Rss:                5728 kB
        Pss:                 912 kB
        Pss_Anon:            436 kB
        Pss_File:           4048 kB
        Pss_Shmem:           244 kB
        Shared_Clean:       5248 kB
        Shared_Dirty:          0 kB
        Private_Clean:       332 kB
        Private_Dirty:       148 kB
        Referenced:         5728 kB
        Anonymous:           436 kB
        LazyFree:              0 kB
        Swap:                  0 kB
        SwapPss:               0 kB
        AnonHugePages:         0 kB
        ShmemPmdMapped:        0 kB
        TXT;

    public function testParseMapsAllDtoFields(): void
    {
        $rollup = new SmapsRollupReader()->parse(self::SMAPS_ROLLUP);

        self::assertSame(5728 * 1024, $rollup->rss);
        self::assertSame(912 * 1024, $rollup->pss);
        self::assertSame(436 * 1024, $rollup->pssAnon);
        self::assertSame(4048 * 1024, $rollup->pssFile);
        self::assertSame(244 * 1024, $rollup->pssShmem);
        self::assertSame(5248 * 1024, $rollup->sharedClean);
        self::assertSame(0, $rollup->sharedDirty);
        self::assertSame(332 * 1024, $rollup->privateClean);
        self::assertSame(148 * 1024, $rollup->privateDirty);
        self::assertSame(436 * 1024, $rollup->anonymous);
        self::assertSame(0, $rollup->anonHugePages);
        self::assertSame(0, $rollup->swap);
    }

    public function testParseSkipsFieldsNotCoveredByDto(): void
    {
        $rollup = new SmapsRollupReader()->parse(self::SMAPS_ROLLUP);

        self::assertSame(SmapsRollup::class, $rollup::class);
    }

    public function testParseEmptyContentReturnsDefaults(): void
    {
        $rollup = new SmapsRollupReader()->parse('');

        self::assertEquals(new SmapsRollup(), $rollup);
    }

    public function testReadLiveProcess(): void
    {
        if (!is_readable('/proc/self/smaps_rollup')) {
            self::markTestSkipped('/proc/self/smaps_rollup is not available');
        }

        $rollup = new SmapsRollupReader()->read();

        self::assertGreaterThan(0, $rollup->rss);
        self::assertGreaterThanOrEqual(0, $rollup->pss);
    }

    public function testReadThrowsForUnknownPid(): void
    {
        $this->expectException(RuntimeException::class);

        new SmapsRollupReader()->read(PHP_INT_MAX);
    }
}
