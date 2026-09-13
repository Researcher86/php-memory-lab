<?php

declare(strict_types=1);

namespace App\Tests\Benchmark;

use App\Benchmark\BenchmarkReport;
use App\Benchmark\BenchmarkResult;
use App\Benchmark\Environment;
use App\Benchmark\Formatter\CsvFormatter;
use App\Benchmark\Formatter\JsonFormatter;
use App\Benchmark\Formatter\TextFormatter;
use App\Benchmark\Timings;
use PHPUnit\Framework\TestCase;

final class FormatterTest extends TestCase
{
    public function testTextOutputCarriesTheEnvironmentAndEveryRow(): void
    {
        $output = new TextFormatter()->format($this->report());

        self::assertStringContainsString('Benchmark suite: example', $output);
        self::assertStringContainsString(PHP_VERSION, $output, 'the environment is part of the result');
        self::assertStringContainsString('fast operation', $output);
        self::assertStringContainsString('slow operation', $output);
        self::assertStringContainsString('universal result', str_replace("\n", ' ', $output));
    }

    public function testJsonOutputIsValidAndKeepsBothMemoryViews(): void
    {
        $decoded = json_decode(new JsonFormatter()->format($this->report()), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertSame('example', $decoded['suite']);
        self::assertSame(PHP_VERSION, $decoded['environment']['php_version']);
        self::assertCount(2, $decoded['results']);
        self::assertSame('fast operation', $decoded['results'][0]['name']);
        self::assertSame(1024, $decoded['results'][0]['php_delta_bytes']);
        self::assertSame(4096, $decoded['results'][0]['rss_delta_bytes']);
        self::assertSame(0.002, $decoded['results'][0]['seconds']['median']);
    }

    public function testAMissingRssDeltaStaysNullRatherThanBecomingZero(): void
    {
        $decoded = json_decode(new JsonFormatter()->format($this->report()), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertNull($decoded['results'][1]['rss_delta_bytes'], 'unavailable is not the same as no change');
    }

    public function testCsvHasOneHeaderAndOneRowPerResult(): void
    {
        $lines = array_values(array_filter(explode("\n", new CsvFormatter()->format($this->report()))));

        self::assertCount(3, $lines);
        self::assertStringStartsWith('suite,benchmark,iterations', $lines[0]);
        self::assertStringStartsWith('example,"fast operation",100', $lines[1]);
        self::assertStringStartsWith('example,"slow operation",10', $lines[2]);
    }

    /**
     * CSV has nowhere to put a header block, so the fields that most often
     * explain a difference between two runs ride along on every row.
     */
    public function testEveryCsvRowRepeatsTheEnvironmentFieldsThatExplainADifference(): void
    {
        $lines = array_values(array_filter(explode("\n", new CsvFormatter()->format($this->report()))));

        self::assertStringContainsString(PHP_VERSION, $lines[1]);
        self::assertStringContainsString(PHP_VERSION, $lines[2]);
    }

    private function report(): BenchmarkReport
    {
        return new BenchmarkReport(
            'example',
            Environment::detect(),
            [
                new BenchmarkResult(
                    name: 'fast operation',
                    iterations: 100,
                    repetitions: 5,
                    elapsedSeconds: 0.01,
                    timings: Timings::of([0.002, 0.002, 0.002, 0.003, 0.001]),
                    phpDelta: 1024,
                    rssDelta: 4096,
                ),
                new BenchmarkResult(
                    name: 'slow operation',
                    iterations: 10,
                    repetitions: 5,
                    elapsedSeconds: 1.0,
                    timings: Timings::of([0.2, 0.2, 0.2, 0.2, 0.2]),
                    phpDelta: -512,
                    rssDelta: null,
                ),
            ],
        );
    }
}
