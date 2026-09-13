<?php

declare(strict_types=1);

namespace App\Tests\Benchmark;

use App\Benchmark\Timings;
use PHPUnit\Framework\TestCase;

final class TimingsTest extends TestCase
{
    public function testASingleSampleIsEveryStatistic(): void
    {
        $timings = Timings::of([0.25]);

        self::assertSame(0.25, $timings->min);
        self::assertSame(0.25, $timings->max);
        self::assertSame(0.25, $timings->mean);
        self::assertSame(0.25, $timings->median);
        self::assertSame(0.25, $timings->p95);
    }

    public function testSamplesDoNotHaveToArriveSorted(): void
    {
        $timings = Timings::of([0.5, 0.1, 0.3]);

        self::assertSame(0.1, $timings->min);
        self::assertSame(0.5, $timings->max);
        self::assertSame(0.3, $timings->median);
    }

    public function testTheMeanIsTheAverageAndTheMedianIsNot(): void
    {
        // One repetition that lost its CPU to something else: the mean
        // follows it and the median does not, which is why ops/sec is
        // derived from the median.
        $timings = Timings::of([0.1, 0.1, 0.1, 0.1, 10.0]);

        self::assertEqualsWithDelta(2.08, $timings->mean, 0.001);
        self::assertSame(0.1, $timings->median);
    }

    public function testAnEvenNumberOfSamplesTakesTheLowerOfTheTwoMiddles(): void
    {
        // Nearest-rank rather than interpolated: with a handful of samples,
        // averaging the two middles invents precision the measurement does
        // not have. Rank ceil(0.5 x 4) = 2, so the second of four.
        self::assertSame(0.2, Timings::of([0.1, 0.2, 0.3, 0.4])->median);
    }

    public function testThe95thPercentileIsTheNineteenthOfTwentySamples(): void
    {
        $samples = [];

        for ($i = 1; $i <= 20; $i++) {
            $samples[] = $i / 100;
        }

        // Rank ceil(0.95 x 20) = 19: the second-worst, not the worst, which
        // is the point of quoting a percentile rather than a maximum.
        self::assertSame(0.19, Timings::of($samples)->p95);
    }
}
