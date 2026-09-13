<?php

declare(strict_types=1);

namespace App\Benchmark;

/**
 * What the machine was, at the moment the numbers were taken.
 *
 * Recorded with every report because a benchmark without its environment is
 * not a measurement, it is an anecdote - and because the fields below are
 * exactly the ones that explain a result being different somewhere else.
 * JIT and OPcache change how the loop itself executes; the allocator changes
 * what `memory_get_usage()` even means; the cgroup limit is what decides
 * whether the page cache was there to be used.
 */
final readonly class Environment
{
    private function __construct(
        public string $phpVersion,
        public string $os,
        public string $architecture,
        public string $cpu,
        public int $cpuCount,
        public string $memoryLimit,
        public ?int $cgroupMemoryLimit,
        public bool $opcacheEnabled,
        public bool $jitEnabled,
        public string $allocator,
        public string $kernel,
    ) {
    }

    public static function detect(): self
    {
        return new self(
            phpVersion: PHP_VERSION,
            os: PHP_OS_FAMILY,
            architecture: php_uname('m'),
            cpu: self::cpuModel(),
            cpuCount: self::cpuCount(),
            memoryLimit: (string) ini_get('memory_limit'),
            cgroupMemoryLimit: self::cgroupMemoryLimit(),
            opcacheEnabled: filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOL),
            jitEnabled: self::jitEnabled(),
            // The engine allocator can be switched off entirely, at which
            // point every PHP allocation goes straight to malloc and the PHP
            // memory counters stop resembling any other run.
            allocator: getenv('USE_ZEND_ALLOC') === '0' ? 'system malloc' : 'Zend MM',
            kernel: php_uname('r'),
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toArray(): array
    {
        return [
            'php_version' => $this->phpVersion,
            'os' => $this->os,
            'architecture' => $this->architecture,
            'cpu' => $this->cpu,
            'cpu_count' => $this->cpuCount,
            'memory_limit' => $this->memoryLimit,
            'cgroup_memory_limit' => $this->cgroupMemoryLimit,
            'opcache' => $this->opcacheEnabled,
            'jit' => $this->jitEnabled,
            'allocator' => $this->allocator,
            'kernel' => $this->kernel,
        ];
    }

    /**
     * /proc/cpuinfo names the CPU on x86 and often does not on a virtualised
     * aarch64 kernel, which lists features and no model at all. The
     * architecture is the honest fallback - better than the string "unknown"
     * in a header whose whole job is to say where a number came from.
     */
    private static function cpuModel(): string
    {
        foreach (self::procLines('/proc/cpuinfo') as $line) {
            if (preg_match('/^(model name|Model|Hardware)\s*:\s*(.+)$/', $line, $matches) === 1) {
                return trim($matches[2]);
            }
        }

        return php_uname('m');
    }

    private static function cpuCount(): int
    {
        $count = 0;

        foreach (self::procLines('/proc/cpuinfo') as $line) {
            if (str_starts_with($line, 'processor')) {
                ++$count;
            }
        }

        return max(1, $count);
    }

    /**
     * The container's memory ceiling, which is usually the number that
     * matters and is never the one `memory_limit` reports. cgroup v2 first,
     * then v1; "max" means unlimited.
     */
    private static function cgroupMemoryLimit(): ?int
    {
        foreach (['/sys/fs/cgroup/memory.max', '/sys/fs/cgroup/memory/memory.limit_in_bytes'] as $path) {
            if (!is_readable($path)) {
                continue;
            }

            $value = trim((string) file_get_contents($path));

            if ($value === 'max' || $value === '') {
                return null;
            }

            // cgroup v1 reports "no limit" as a number near PHP_INT_MAX.
            $limit = (int) $value;

            return $limit > 0 && $limit < PHP_INT_MAX / 2 ? $limit : null;
        }

        return null;
    }

    private static function jitEnabled(): bool
    {
        if (!function_exists('opcache_get_status')) {
            return false;
        }

        $status = @opcache_get_status(false);

        return is_array($status) && ($status['jit']['enabled'] ?? false) === true;
    }

    /**
     * @return list<string>
     */
    private static function procLines(string $path): array
    {
        if (!is_readable($path)) {
            return [];
        }

        return file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    }
}
