<?php

declare(strict_types=1);

namespace App\Benchmark\Formatter;

use App\Benchmark\BenchmarkReport;

/** For keeping. Whole report in one object, environment included. */
final class JsonFormatter implements Formatter
{
    public function format(BenchmarkReport $report): string
    {
        $results = [];

        foreach ($report->results as $result) {
            $results[] = [
                'name' => $result->name,
                'iterations' => $result->iterations,
                'repetitions' => $result->repetitions,
                'elapsed_seconds' => $result->elapsedSeconds,
                'seconds' => [
                    'min' => $result->timings->min,
                    'max' => $result->timings->max,
                    'mean' => $result->timings->mean,
                    'median' => $result->timings->median,
                    'p95' => $result->timings->p95,
                ],
                'operations_per_second' => $result->operationsPerSecond(),
                'seconds_per_operation' => $result->secondsPerOperation(),
                'php_delta_bytes' => $result->phpDelta,
                'rss_delta_bytes' => $result->rssDelta,
            ];
        }

        return json_encode(
            [
                'suite' => $report->suite,
                'environment' => $report->environment->toArray(),
                'results' => $results,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
    }
}
