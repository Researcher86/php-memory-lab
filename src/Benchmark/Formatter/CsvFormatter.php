<?php

declare(strict_types=1);

namespace App\Benchmark\Formatter;

use App\Benchmark\BenchmarkReport;

/**
 * For comparing across runs in a spreadsheet. The environment cannot be a
 * header here - CSV has no room for one - so the fields that most often
 * explain a difference are repeated on every row instead of being dropped.
 */
final class CsvFormatter implements Formatter
{
    private const COLUMNS = [
        'suite',
        'benchmark',
        'iterations',
        'repetitions',
        'median_seconds',
        'min_seconds',
        'max_seconds',
        'mean_seconds',
        'p95_seconds',
        'operations_per_second',
        'php_delta_bytes',
        'rss_delta_bytes',
        'php_version',
        'cpu',
        'jit',
        'allocator',
    ];

    public function format(BenchmarkReport $report): string
    {
        $environment = $report->environment;
        $rows = [self::COLUMNS];

        foreach ($report->results as $result) {
            $rows[] = [
                $report->suite,
                $result->name,
                (string) $result->iterations,
                (string) $result->repetitions,
                \sprintf('%.9f', $result->timings->median),
                \sprintf('%.9f', $result->timings->min),
                \sprintf('%.9f', $result->timings->max),
                \sprintf('%.9f', $result->timings->mean),
                \sprintf('%.9f', $result->timings->p95),
                \sprintf('%.3f', $result->operationsPerSecond()),
                (string) $result->phpDelta,
                $result->rssDelta === null ? '' : (string) $result->rssDelta,
                $environment->phpVersion,
                $environment->cpu,
                $environment->jitEnabled ? '1' : '0',
                $environment->allocator,
            ];
        }

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('Unable to open a buffer for CSV output');
        }

        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '\\');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
