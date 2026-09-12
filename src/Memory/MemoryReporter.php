<?php

declare(strict_types=1);

namespace App\Memory;

use RuntimeException;

/**
 * The composition point for Phase 1 measurements: joins PHP allocator
 * counters with the two /proc readers and turns two snapshots into a diff.
 * A failed /proc read degrades to null OS fields, so a snapshot still works
 * on a non-Linux host for the PHP-only fields.
 */
final readonly class MemoryReporter
{
    public function __construct(
        private ProcStatusReader $statusReader = new ProcStatusReader(),
        private SmapsRollupReader $smapsRollupReader = new SmapsRollupReader(),
    ) {}

    public function snapshot(): MemorySnapshot
    {
        $status = $this->safeStatus();
        $pss = $this->safePss();

        return new MemorySnapshot(
            phpUsage: memory_get_usage(),
            phpPeakUsage: memory_get_peak_usage(),
            phpRealUsage: memory_get_usage(true),
            phpRealPeakUsage: memory_get_peak_usage(true),
            rss: self::intOrNull($status['VmRSS'] ?? null),
            virtualMemory: self::intOrNull($status['VmSize'] ?? null),
            sharedMemory: self::intOrNull($status['RssShmem'] ?? null),
            privateMemory: self::intOrNull($status['RssAnon'] ?? null),
            pss: $pss,
        );
    }

    public function diff(MemorySnapshot $before, MemorySnapshot $after): MemoryDiff
    {
        return new MemoryDiff(
            phpUsage: $after->phpUsage - $before->phpUsage,
            phpPeakUsage: $after->phpPeakUsage - $before->phpPeakUsage,
            phpRealUsage: $after->phpRealUsage - $before->phpRealUsage,
            phpRealPeakUsage: $after->phpRealPeakUsage - $before->phpRealPeakUsage,
            rss: self::nullableDelta($before->rss, $after->rss),
            virtualMemory: self::nullableDelta($before->virtualMemory, $after->virtualMemory),
            sharedMemory: self::nullableDelta($before->sharedMemory, $after->sharedMemory),
            privateMemory: self::nullableDelta($before->privateMemory, $after->privateMemory),
            pss: self::nullableDelta($before->pss, $after->pss),
        );
    }

    /**
     * @return array<string, int|string>|null
     */
    private function safeStatus(): ?array
    {
        try {
            return $this->statusReader->read();
        } catch (RuntimeException) {
            return null;
        }
    }

    private function safePss(): ?int
    {
        try {
            return $this->smapsRollupReader->read()->pss;
        } catch (RuntimeException) {
            return null;
        }
    }

    private static function intOrNull(int|string|null $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function nullableDelta(?int $before, ?int $after): ?int
    {
        if ($before === null || $after === null) {
            return null;
        }

        return $after - $before;
    }
}
