<?php

declare(strict_types=1);

namespace App\Benchmark\Formatter;

use App\Benchmark\BenchmarkReport;

/** Renders a finished report. One implementation per output format. */
interface Formatter
{
    public function format(BenchmarkReport $report): string;
}
