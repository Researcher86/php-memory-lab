<?php

declare(strict_types=1);

namespace App\Tests\Experiment;

use App\Experiment\ExperimentRunner;
use App\Experiment\Options;
use App\Experiment\Output;
use App\Experiment\Registry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ExperimentRunnerTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
            @rmdir(\dirname($path));
            @rmdir(\dirname($path, 2));
        }

        $this->paths = [];
    }

    public function testTheResultCarriesTheOptionsTheRunActuallyUsed(): void
    {
        $runner = new ExperimentRunner($this->registry());

        $result = $runner->run(
            'fixture:records',
            Options::fromStrings(['elements' => '7']),
            new Output(quiet: true),
        );

        self::assertSame('fixture:records', $result->name);
        self::assertSame(['elements' => 7], $result->options);
        self::assertSame(['elements' => 7, 'doubled' => 14], $result->measurements);
        self::assertGreaterThan(0.0, $result->elapsedSeconds);
    }

    /**
     * The check lives in the runner so that every experiment refuses an
     * option it does not honour in the same words, and none of them has to
     * remember to.
     */
    public function testAnOptionTheExperimentDoesNotUseIsRefused(): void
    {
        $runner = new ExperimentRunner($this->registry());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('fixture:records does not use --children');

        $runner->run('fixture:records', Options::fromStrings(['children' => '2']), new Output(quiet: true));
    }

    public function testAnExperimentWithNoOptionsSaysSo(): void
    {
        $runner = new ExperimentRunner($this->registry());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('It understands: no options');

        $runner->run('fixture:plain', Options::fromStrings(['size' => '8']), new Output(quiet: true));
    }

    public function testTheDefaultIsUsedWhenNothingIsPassed(): void
    {
        $result = new ExperimentRunner($this->registry())
            ->run('fixture:records', Options::none(), new Output(quiet: true));

        self::assertSame([], $result->options, 'an unset option is not reported as its default');
        self::assertSame(3, $result->measurements['elements'], 'but the experiment still gets one');
    }

    public function testTheJsonShapeIsStable(): void
    {
        $result = new ExperimentRunner($this->registry())
            ->run('fixture:records', Options::none(), new Output(quiet: true));

        $decoded = json_decode(json_encode($result->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertSame('fixture:records', $decoded['experiment']);
        self::assertSame([], $decoded['options']);
        self::assertSame(3, $decoded['measurements']['elements']);
    }

    private function registry(): Registry
    {
        $directory = sys_get_temp_dir() . '/runner-test-' . bin2hex(random_bytes(6));
        $topic = $directory . '/01-topic';
        mkdir($topic, 0o777, true);

        $this->write($topic . '/records.php', <<<'PHP'
            <?php

            use App\Experiment\Experiment;
            use App\Experiment\Options;
            use App\Experiment\Output;

            return new Experiment(
                name: 'fixture:records',
                description: 'records what it was given',
                supports: ['elements'],
                run: static function (Options $options, Output $out): void {
                    $elements = $options->elements(3);
                    $out->measure('elements', $elements);
                    $out->measure('doubled', $elements * 2);
                },
            );
            PHP);

        $this->write($topic . '/plain.php', <<<'PHP'
            <?php

            use App\Experiment\Experiment;
            use App\Experiment\Options;
            use App\Experiment\Output;

            return new Experiment(
                name: 'fixture:plain',
                description: 'takes no options at all',
                supports: [],
                run: static function (Options $options, Output $out): void {},
            );
            PHP);

        return new Registry($directory);
    }

    private function write(string $path, string $contents): void
    {
        file_put_contents($path, $contents);
        $this->paths[] = $path;
    }
}
