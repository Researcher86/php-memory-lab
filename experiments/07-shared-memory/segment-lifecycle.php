#!/usr/bin/env php
<?php

declare(strict_types=1);

require \dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../run-helpers.php';

use App\Ipc\Exception\SharedMemoryException;
use App\Ipc\SharedMemorySegment;
use App\Memory\ByteFormatter;

experiment_start('SysV shared memory: what it really stores, and how long it lives');

/**
 * The kernel's own view of a segment. Two columns matter here: nattch, the
 * number of processes currently attached, and the fact that a row exists at
 * all - a segment with nattch 0 is still a segment.
 *
 * @return array{size: int, attached: int}|null
 */
function segment_row(int $key): ?array
{
    $lines = \file('/proc/sysvipc/shm', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    foreach (\array_slice($lines, 1) as $line) {
        $columns = \preg_split('/\s+/', \trim($line)) ?: [];

        if (isset($columns[0]) && (int) $columns[0] === $key) {
            return ['size' => (int) $columns[3], 'attached' => (int) $columns[6]];
        }
    }

    return null;
}

$reporter = experiment_reporter();
$key = SharedMemorySegment::randomKey();
$segment = SharedMemorySegment::attach($key, 1024 * 1024);

\fwrite(STDOUT, \sprintf("\nSegment key 0x%x attached by pid %d.\n", $key, \getmypid()));

$row = segment_row($key);
\fwrite(STDOUT, \sprintf(
    "Kernel says: size %s, %d process(es) attached.\n",
    ByteFormatter::format($row['size'] ?? 0),
    $row['attached'] ?? 0,
));

/*
 * Point one: this is not shared memory in the sense of shared variables. PHP
 * serializes on the way in and deserializes on the way out, so what comes
 * back is a new value that merely looks like the old one.
 */
$original = ['counter' => 1, 'tags' => ['a', 'b']];
$segment->put(1, $original);

$copy = $segment->get(1);
\assert(\is_array($copy));
$copy['counter'] = 999;
$segment->put(2, $copy);

\fwrite(STDOUT, \sprintf(
    "\nStored counter=1, read it back, set the copy to 999, stored it separately.\n  index 1 is still %d - modifying what get() returned changed nothing shared.\n",
    $segment->get(1)['counter'],
));

$object = new stdClass();
$object->id = 7;
$segment->put(3, $object);
$restored = $segment->get(3);

\fwrite(STDOUT, \sprintf(
    "  an object round-trips by value too: same class %s, same id %d, different instance (%s).\n",
    \get_debug_type($restored),
    $restored->id,
    $restored === $object ? 'identical - impossible' : 'as expected',
));

/*
 * Point two: that serialization has a price, and it is paid on every access.
 */
$rows = \array_fill(0, 5_000, ['id' => 1, 'name' => 'user', 'score' => 10]);
$iterations = 50;

$start = \hrtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $segment->put(10, $rows);
}
$putNs = (\hrtime(true) - $start) / $iterations;

$start = \hrtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $segment->get(10);
}
$getNs = (\hrtime(true) - $start) / $iterations;

$start = \hrtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $localCopy = $rows;
}
$copyNs = (\hrtime(true) - $start) / $iterations;

\fwrite(STDOUT, \sprintf(
    "\nA 5,000-row array (%s serialized):\n  put()  %7.3f ms\n  get()  %7.3f ms\n  a plain PHP copy of the same array: %.6f ms (refcount++, nothing moves)\n",
    ByteFormatter::format(\strlen(\serialize($rows))),
    $putNs / 1e6,
    $getNs / 1e6,
    $copyNs / 1e6,
));

/*
 * Point three: the segment is a fixed-size allocation, and the limit applies
 * to the serialized form plus per-variable bookkeeping - not to anything PHP
 * can see from the value itself.
 */
$small = SharedMemorySegment::attach(SharedMemorySegment::randomKey(), 4096);
$stored = 0;

for ($size = 256; $size <= 8192; $size += 256) {
    try {
        $small->put(1, \str_repeat('x', $size));
        $stored = $size;
    } catch (SharedMemoryException) {
        \fwrite(STDOUT, \sprintf(
            "\nA 4.00 KiB segment refused a %s payload; the largest it took was %s.\n",
            ByteFormatter::format($size),
            ByteFormatter::format($stored),
        ));

        break;
    }
}

$small->destroy();

/*
 * Where does the memory show up? Not in PHP's counters: the engine never
 * allocated it. The kernel charges the pages this process has touched to
 * RssShmem, which is part of RSS and no part of memory_get_usage().
 */
$beforeTouch = $reporter->snapshot();
$segment->put(20, \str_repeat('x', 512 * 1024));
$segment->get(20);
$afterTouch = $reporter->snapshot();
$touchDelta = $reporter->diff($beforeTouch, $afterTouch);

\fwrite(STDOUT, \sprintf(
    "\nWriting and reading 512.00 KiB through the segment:\n  PHP usage %s   RSS %s   RssShmem %s\n",
    ByteFormatter::formatSigned($touchDelta->phpUsage),
    $touchDelta->rss === null ? 'n/a' : ByteFormatter::formatSigned($touchDelta->rss),
    $touchDelta->sharedMemory === null ? 'n/a' : ByteFormatter::formatSigned($touchDelta->sharedMemory),
));

experiment_note('shared pages are charged to RssShmem in /proc/self/status - part of RSS, and no part of memory_get_usage(). A process whose PHP memory looks flat can still be holding megabytes this way.');

/*
 * Point four, the one that bites in production: a segment is owned by the
 * kernel, not by the process that made it. Detaching is not removing, and a
 * process killed outright removes nothing at all.
 */
$segment->detach();
$row = segment_row($key);

\fwrite(STDOUT, \sprintf(
    "\nAfter detach(): the segment is %s, now with %d process(es) attached.\n",
    $row === null ? 'gone' : 'still there',
    $row['attached'] ?? 0,
));

$pid = \pcntl_fork();

if ($pid === 0) {
    SharedMemorySegment::attach($key);
    // SIGKILL: no destructors, no cleanup, no chance to detach politely.
    \posix_kill(\posix_getpid(), SIGKILL);
}

\pcntl_waitpid($pid, $status);
$row = segment_row($key);

\fwrite(STDOUT, \sprintf(
    "After a child attached and was SIGKILLed: segment %s (%d attached).\n",
    $row === null ? 'gone' : 'still there',
    $row['attached'] ?? 0,
));

SharedMemorySegment::attach($key)->destroy();

\fwrite(STDOUT, \sprintf(
    "After an explicit destroy(): segment %s.\n",
    segment_row($key) === null ? 'gone' : 'STILL THERE - leaked',
));

experiment_note('a segment survives every process that touched it; only shm_remove() or a reboot takes it away. That is why `ipcs -m` on a long-lived box is full of segments nobody owns any more, and why every test and experiment here cleans up by key rather than by object.');
experiment_context();
