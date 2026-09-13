<?php

declare(strict_types=1);

namespace App\Tests\Cli;

use App\Cli\Arguments;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ArgumentsTest extends TestCase
{
    public function testACommandOnItsOwn(): void
    {
        $arguments = Arguments::parse(['memory:empty']);

        self::assertSame('memory:empty', $arguments->command);
        self::assertSame([], $arguments->except());
    }

    public function testOptionsAreCollectedWhereverTheyAppear(): void
    {
        $arguments = Arguments::parse(['--size=4096', 'mmap:lazy', '--format=json']);

        self::assertSame('mmap:lazy', $arguments->command);
        self::assertSame('json', $arguments->string('format', 'text'));
        self::assertSame(4096, $arguments->positiveInt('size', 1));
    }

    public function testAMissingCommandIsNotAnError(): void
    {
        // `bin/experiment` with no arguments prints its usage rather than
        // failing, so an absent command has to be representable.
        self::assertNull(Arguments::parse(['--format=json'])->command);
    }

    public function testAnEmptyValueIsAValue(): void
    {
        self::assertSame('', Arguments::parse(['--output='])->string('output', 'fallback'));
    }

    public function testDefaultsApplyToOptionsThatWereNotPassed(): void
    {
        $arguments = Arguments::parse(['suite']);

        self::assertSame('text', $arguments->string('format', 'text'));
        self::assertSame(5, $arguments->positiveInt('repetitions', 5));
        self::assertFalse($arguments->has('output'));
    }

    public function testASecondCommandIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unrecognised argument: second');

        Arguments::parse(['first', 'second']);
    }

    public function testABareFlagIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Arguments::parse(['--verbose']);
    }

    public function testANonNumericValueIsRefusedWhereANumberIsExpected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--iterations must be a positive integer');

        Arguments::parse(['--iterations=many'])->positiveInt('iterations', 1);
    }

    public function testZeroIsRefusedWhereAPositiveNumberIsExpected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Arguments::parse(['--repetitions=0'])->positiveInt('repetitions', 5);
    }

    public function testExceptLeavesTheRestAlone(): void
    {
        $arguments = Arguments::parse(['run', '--format=json', '--children=4', '--elements=100']);

        self::assertSame(['children' => '4', 'elements' => '100'], $arguments->except('format', 'output'));
    }
}
