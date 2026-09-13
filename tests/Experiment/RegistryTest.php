<?php

declare(strict_types=1);

namespace App\Tests\Experiment;

use App\Experiment\Experiment;
use App\Experiment\Registry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RegistryTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
            @rmdir(\dirname($path));
        }

        $this->paths = [];
    }

    /**
     * The registry is discovery rather than a list, so the test that matters
     * is that the whole directory loads: a descriptor with a syntax error or
     * a duplicated name fails here rather than the first time someone runs
     * that one experiment.
     */
    public function testEveryExperimentInTheProjectLoadsAndIsNamedOnce(): void
    {
        $registry = new Registry(\dirname(__DIR__, 2) . '/experiments');
        $experiments = $registry->all();

        self::assertGreaterThan(20, \count($experiments));

        foreach ($experiments as $name => $experiment) {
            self::assertSame($name, $experiment->name, 'the index key is the declared name');
            self::assertMatchesRegularExpression('/^[a-z]+:[a-z-]+$/', $name);
            self::assertNotSame('', $experiment->description, $name . ' has no description');
        }
    }

    public function testEveryDeclaredOptionIsOneTheCliKnows(): void
    {
        $registry = new Registry(\dirname(__DIR__, 2) . '/experiments');

        foreach ($registry->all() as $name => $experiment) {
            foreach ($experiment->supports as $option) {
                self::assertArrayHasKey(
                    $option,
                    \App\Experiment\Options::KNOWN,
                    $name . ' declares an option the CLI cannot parse',
                );
            }
        }
    }

    public function testNamesAreSortedSoTheListingIsStable(): void
    {
        $names = array_keys(new Registry(\dirname(__DIR__, 2) . '/experiments')->all());
        $sorted = $names;
        sort($sorted);

        self::assertSame($sorted, $names);
    }

    public function testAnUnknownNameThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown experiment: nothing:here');

        new Registry(\dirname(__DIR__, 2) . '/experiments')->get('nothing:here');
    }

    public function testHasDistinguishesKnownFromUnknown(): void
    {
        $registry = new Registry(\dirname(__DIR__, 2) . '/experiments');

        self::assertTrue($registry->has('memory:empty'));
        self::assertFalse($registry->has('memory:nonexistent'));
    }

    public function testAFileThatReturnsSomethingElseIsRejected(): void
    {
        $directory = $this->fixture("<?php\n\nreturn ['not an experiment'];\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not return an Experiment');

        new Registry($directory)->all();
    }

    public function testTwoExperimentsWithOneNameAreRejected(): void
    {
        $descriptor = <<<'PHP'
            <?php

            use App\Experiment\Experiment;
            use App\Experiment\Options;
            use App\Experiment\Output;

            return new Experiment('dup:name', 'duplicated', [], static function (Options $o, Output $out): void {});
            PHP;

        $directory = $this->fixture($descriptor, 'one.php');
        $this->fixture($descriptor, 'two.php', $directory);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Two experiments are called dup:name');

        new Registry($directory)->all();
    }

    public function testAnExperimentIsHandedBackWhole(): void
    {
        $experiment = new Registry(\dirname(__DIR__, 2) . '/experiments')->get('memory:empty');

        self::assertInstanceOf(Experiment::class, $experiment);
        self::assertSame(['elements'], $experiment->supports);
    }

    private function fixture(string $contents, string $file = 'one.php', ?string $directory = null): string
    {
        $directory ??= sys_get_temp_dir() . '/registry-test-' . bin2hex(random_bytes(6));
        $topic = $directory . '/01-topic';

        if (!is_dir($topic)) {
            mkdir($topic, 0o777, true);
        }

        $path = $topic . '/' . $file;
        file_put_contents($path, $contents);
        $this->paths[] = $path;

        return $directory;
    }
}
