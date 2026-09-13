<?php

declare(strict_types=1);

namespace App\Tests\Experiment;

use App\Experiment\Options;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase
{
    public function testAnOptionThatWasNotPassedFallsBackToTheCallersDefault(): void
    {
        $options = Options::none();

        self::assertSame(1_000, $options->elements(1_000));
        self::assertSame(4, $options->children(4));
        self::assertSame(64, $options->payloadSize(64));
    }

    public function testAPassedOptionOverridesTheDefault(): void
    {
        $options = Options::fromStrings(['elements' => '25', 'children' => '8']);

        self::assertSame(25, $options->elements(1_000));
        self::assertSame(8, $options->children(4));
        self::assertSame(64, $options->payloadSize(64), 'untouched options keep their default');
    }

    /**
     * Silently accepting an option nobody reads is how a run gets reported
     * under a configuration it never had.
     */
    public function testAnUnknownOptionIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown option --colour');

        Options::fromStrings(['colour' => 'blue']);
    }

    public function testZeroIsRefusedBecauseItIsADifferentExperimentNotASmallerOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Options::fromStrings(['children' => '0']);
    }

    public function testANonNumericValueIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a positive integer');

        Options::fromStrings(['elements' => 'lots']);
    }

    public function testANegativeValueIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Options::fromStrings(['size' => '-1']);
    }

    public function testOnlyWhatWasPassedIsReported(): void
    {
        $options = Options::fromStrings(['size' => '4096']);

        self::assertSame(['size'], $options->names());
        self::assertSame(['size' => 4096], $options->toArray(), 'defaults are the caller\'s, not the report\'s');
    }
}
