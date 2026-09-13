<?php

declare(strict_types=1);

namespace App\Tests;

use App\Experiment\Registry;
use PHPUnit\Framework\TestCase;

/**
 * The documentation is half of what this project is, and it goes stale the
 * same way code does - by the thing it describes moving. These checks are
 * mechanical because eyes are what miss a renamed file.
 */
final class DocumentationTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    public function testEveryDocumentIsLinkedFromTheReadme(): void
    {
        $readme = (string) file_get_contents(self::ROOT . '/README.md');

        foreach ($this->documents() as $document) {
            self::assertStringContainsString(
                'docs/' . basename($document),
                $readme,
                basename($document) . ' exists but nothing in README points at it',
            );
        }
    }

    public function testEveryRelativeLinkResolves(): void
    {
        foreach ([...$this->documents(), self::ROOT . '/README.md'] as $document) {
            $directory = dirname($document);

            foreach ($this->linksIn($document) as $target) {
                // A bare "#anchor" link points inside the same document and
                // has no file to check.
                $path = strtok($target, '#');

                if ($path === false || str_starts_with($target, '#')) {
                    continue;
                }

                self::assertFileExists(
                    $directory . '/' . $path,
                    sprintf('%s links to %s, which does not exist', basename($document), $target),
                );
            }
        }
    }

    /**
     * A path in backticks reads as a claim that the file is there. Phase 11
     * moved thirty-two of them at once, which is the kind of change that
     * leaves a document describing a directory that no longer looks like that.
     */
    public function testEveryPathMentionedInBackticksExists(): void
    {
        foreach ([...$this->documents(), self::ROOT . '/README.md'] as $document) {
            $contents = (string) file_get_contents($document);
            $matches = [];
            preg_match_all(
                '/`((?:src|tests|bin|benchmarks|experiments|docs)\/[A-Za-z0-9_.\/-]+\.(?:php|md|neon|json))`/',
                $contents,
                $matches,
            );

            foreach (array_unique($matches[1]) as $path) {
                self::assertFileExists(
                    self::ROOT . '/' . $path,
                    sprintf('%s mentions %s, which does not exist', basename($document), $path),
                );
            }
        }
    }

    /**
     * Every command a document tells the reader to run has to be one the CLI
     * still answers to.
     */
    public function testEveryExperimentCommandInTheDocsExists(): void
    {
        $registry = new Registry(self::ROOT . '/experiments');

        foreach ([...$this->documents(), self::ROOT . '/README.md'] as $document) {
            $matches = [];
            preg_match_all('/make experiment ARGS="([a-z]+:[a-z-]+)/', (string) file_get_contents($document), $matches);

            foreach (array_unique($matches[1]) as $name) {
                self::assertTrue(
                    $registry->has($name),
                    sprintf('%s tells the reader to run %s, which no longer exists', basename($document), $name),
                );
            }
        }
    }

    public function testEveryBenchmarkSuiteInTheDocsExists(): void
    {
        foreach ([...$this->documents(), self::ROOT . '/README.md'] as $document) {
            $matches = [];
            preg_match_all('/make benchmark\s+ARGS="([a-z]+)/', (string) file_get_contents($document), $matches);

            foreach (array_unique($matches[1]) as $suite) {
                self::assertFileExists(
                    self::ROOT . '/benchmarks/' . $suite . '.php',
                    sprintf('%s names benchmark suite %s, which does not exist', basename($document), $suite),
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private function documents(): array
    {
        return glob(self::ROOT . '/docs/*.md') ?: [];
    }

    /**
     * @return list<string>
     */
    private function linksIn(string $document): array
    {
        $matches = [];
        preg_match_all('/\[[^\]]*\]\(([^)]+)\)/', (string) file_get_contents($document), $matches);

        return array_values(array_filter(
            $matches[1],
            static fn (string $target): bool => !str_starts_with($target, 'http'),
        ));
    }
}
