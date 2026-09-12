<?php

declare(strict_types=1);

namespace MemoryLab\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The lab relies on Linux primitives that a typical PHP installation does not
 * necessarily expose. Keeping this check in the suite makes a broken local
 * container visible before an experiment fails much further down the line.
 */
final class PlatformRequirementsTest extends TestCase
{
    public function testRequiredExtensionsAreAvailable(): void
    {
        foreach ([
            'ffi',
            'pcntl',
            'posix',
            'sockets',
            'sysvmsg',
            'sysvsem',
            'sysvshm',
        ] as $extension) {
            self::assertTrue(\extension_loaded($extension), \sprintf('The %s extension is required.', $extension));
        }
    }
}
