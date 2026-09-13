<?php

declare(strict_types=1);

namespace App\Benchmark\Formatter;

use App\Benchmark\BenchmarkReport;
use App\Memory\ByteFormatter;

/** For reading. Fixed columns, so two runs can be diffed by eye. */
final class TextFormatter implements Formatter
{
    public function format(BenchmarkReport $report): string
    {
        $environment = $report->environment;
        $output = \sprintf("Benchmark suite: %s\n", $report->suite);
        $output .= \sprintf(
            "PHP %s on %s %s, %s x %d, memory_limit %s, cgroup %s\n",
            $environment->phpVersion,
            $environment->os,
            $environment->kernel,
            $environment->cpu,
            $environment->cpuCount,
            $environment->memoryLimit,
            $environment->cgroupMemoryLimit === null
                ? 'unlimited'
                : ByteFormatter::format($environment->cgroupMemoryLimit),
        );
        $output .= \sprintf(
            "OPcache %s, JIT %s, allocator %s\n\n",
            $environment->opcacheEnabled ? 'on' : 'off',
            $environment->jitEnabled ? 'on' : 'off',
            $environment->allocator,
        );

        $output .= \sprintf(
            "%-30s | %8s | %10s %10s %10s | %12s | %10s %10s\n",
            'benchmark',
            'iters',
            'median',
            'min',
            'max',
            'ops/sec',
            'PHP',
            'RSS',
        );
        $output .= str_repeat('-', 108) . "\n";

        foreach ($report->results as $result) {
            $output .= \sprintf(
                "%-30s | %8s | %8.3f ms %8.3f ms %8.3f ms | %12s | %10s %10s\n",
                $result->name,
                number_format($result->iterations),
                $result->timings->median * 1000,
                $result->timings->min * 1000,
                $result->timings->max * 1000,
                number_format($result->operationsPerSecond()),
                ByteFormatter::formatSigned($result->phpDelta),
                $result->rssDelta === null ? 'n/a' : ByteFormatter::formatSigned($result->rssDelta),
            );
        }

        $output .= "\nTimings are per repetition of the whole iteration loop; ops/sec is\n";
        $output .= "derived from the median, not the mean. No number here is a universal\n";
        $output .= "result - it describes the machine in the header and nothing else.\n";

        return $output;
    }
}
