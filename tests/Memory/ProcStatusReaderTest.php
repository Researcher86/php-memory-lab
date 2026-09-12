<?php

declare(strict_types=1);

namespace App\Tests\Memory;

use App\Memory\ProcStatusReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProcStatusReaderTest extends TestCase
{
    private const STATUS = <<<'TXT'
        Name:	php
        State:	S (sleeping)
        Tgid:	31787
        TracerPid:	0
        Threads:	1
        VmPeak:	   43468 kB
        VmSize:	   43468 kB
        VmLck:	       0 kB
        VmHWM:	    6608 kB
        VmRSS:	    6608 kB
        RssAnon:	    436 kB
        RssFile:	    5572 kB
        RssShmem:	     600 kB
        VmData:	    7492 kB
        VmStk:	     132 kB
        VmExe:	    1448 kB
        VmLib:	    3700 kB
        VmPTE:	      84 kB
        a line without a colon is not a field
        TXT;

    public function testParseConvertsKbFieldsToBytes(): void
    {
        $result = new ProcStatusReader()->parse(self::STATUS);

        self::assertSame(6608 * 1024, $result['VmRSS']);
        self::assertSame(43468 * 1024, $result['VmSize']);
        self::assertSame(600 * 1024, $result['RssShmem']);
    }

    public function testParseKeepsPlainIntegers(): void
    {
        $result = new ProcStatusReader()->parse(self::STATUS);

        self::assertSame(1, $result['Threads']);
        self::assertSame(0, $result['TracerPid']);
    }

    public function testParseKeepsNonNumericValues(): void
    {
        $result = new ProcStatusReader()->parse(self::STATUS);

        self::assertSame('php', $result['Name']);
        self::assertSame('S (sleeping)', $result['State']);
    }

    public function testParseIgnoresLinesWithoutColon(): void
    {
        $result = new ProcStatusReader()->parse(self::STATUS);

        self::assertArrayNotHasKey('a line without a colon is not a field', $result);
    }

    public function testReadReturnsLiveStatsForCurrentProcess(): void
    {
        if (!is_readable('/proc/self/status')) {
            self::markTestSkipped('/proc is not available on this host');
        }

        $result = new ProcStatusReader()->read();

        self::assertArrayHasKey('VmRSS', $result);
        self::assertIsInt($result['VmRSS']);
        self::assertGreaterThan(0, $result['VmRSS']);
    }

    public function testReadThrowsForUnknownPid(): void
    {
        $this->expectException(RuntimeException::class);

        new ProcStatusReader()->read(PHP_INT_MAX);
    }
}
