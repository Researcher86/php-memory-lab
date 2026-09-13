#!/usr/bin/env php
<?php

declare(strict_types=1);

require \dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../run-helpers.php';

use App\Ipc\Exception\RingBufferCorruptedException;
use App\Ipc\Exception\RingBufferException;
use App\Ipc\RingBuffer;
use App\Ipc\SharedMemorySegment;

experiment_start('what a ring buffer does when things are not fine');

/*
 * A segment is a region of bytes that any process holding the key can open.
 * Nothing about it says which program formatted it, what version that program
 * spoke, or whether the process writing it is still alive. Every check below
 * exists because the alternative is reading somebody else's data as if it
 * were a message.
 */

/** Runs $case and prints what it refused, or a warning if it refused nothing. */
function refuses(string $title, callable $case): void
{
    try {
        $case();
        \fwrite(STDOUT, \sprintf("  %-36s ACCEPTED IT - no check fired\n", $title));
    } catch (RingBufferException $e) {
        \fwrite(STDOUT, \sprintf("  %-36s %s\n", $title, $e->getMessage()));
    }
}

\fwrite(STDOUT, "\nRefused at attach() or push():\n");

refuses('a key with nothing behind it', static function (): void {
    RingBuffer::attach(SharedMemorySegment::randomKey());
});

refuses('a segment holding other data', static function (): void {
    $key = SharedMemorySegment::randomKey();
    $foreign = \shmop_open($key, 'c', 0666, 256);

    if ($foreign === false) {
        return;
    }

    \shmop_write($foreign, \str_repeat("\x07", 256), 0);

    try {
        RingBuffer::attach($key);
    } finally {
        \shmop_delete($foreign);
    }
});

refuses('a segment too small for a header', static function (): void {
    $key = SharedMemorySegment::randomKey();
    $tiny = \shmop_open($key, 'c', 0666, 8);

    if ($tiny === false) {
        return;
    }

    try {
        RingBuffer::attach($key);
    } finally {
        \shmop_delete($tiny);
    }
});

refuses('a buffer written by a newer version', static function (): void {
    $buffer = RingBuffer::create(SharedMemorySegment::randomKey(), 2, 16);

    // Straight into the header, because a future version of this code is the
    // only thing that could produce it honestly.
    $raw = \shmop_open($buffer->key, 'w', 0, 0);

    if ($raw !== false) {
        \shmop_write($raw, \pack('N', RingBuffer::VERSION + 1), 4);
    }

    try {
        RingBuffer::attach($buffer->key);
    } finally {
        $buffer->destroy();
    }
});

refuses('a message larger than a slot', static function (): void {
    $buffer = RingBuffer::create(SharedMemorySegment::randomKey(), 2, 16);

    try {
        $buffer->push(\str_repeat('x', 17));
    } finally {
        $buffer->destroy();
    }
});

/*
 * The one that cannot be checked in advance: a process that stops existing
 * between the two writes that were supposed to happen together. The kernel
 * hands the semaphore back (SEM_UNDO), so the next process gets a clean lock
 * over a buffer that is not clean - and the busy pid in the header is the
 * only thing that says so.
 */
$buffer = RingBuffer::create(SharedMemorySegment::randomKey(), 32, 64);
$payload = \str_repeat('x', 64);
$attempts = 0;
$caught = false;

while (!$caught && $attempts < 50) {
    ++$attempts;
    $pid = \pcntl_fork();

    if ($pid === 0) {
        $producer = RingBuffer::attach($buffer->key);

        while (true) {
            if (!$producer->push($payload)) {
                $producer->pop();
            }
        }
    }

    // Long enough to be somewhere inside a push, short enough to still be
    // running. Killing a process at a chosen instruction is not something a
    // test can do, so this is a sampling problem: try until it lands.
    \usleep(\random_int(200, 3_000));
    \posix_kill($pid, SIGKILL);
    \pcntl_waitpid($pid, $status);

    $caught = $buffer->busyPid() !== 0;
}

\fwrite(STDOUT, "\nA producer SIGKILLed while it held the lock:\n");

if (!$caught) {
    \fwrite(STDOUT, \sprintf("  never landed inside a critical section in %d tries - the window is small\n", $attempts));
} else {
    \fwrite(STDOUT, \sprintf(
        "  caught on attempt %d: header still names pid %d as mid-update\n",
        $attempts,
        $buffer->busyPid(),
    ));

    try {
        $buffer->pop();
        \fwrite(STDOUT, "  pop() returned anyway - the check did not fire\n");
    } catch (RingBufferCorruptedException $e) {
        \fwrite(STDOUT, \sprintf("  pop() refuses: %s\n", $e->getMessage()));
    }

    \fwrite(STDOUT, \sprintf(
        "  the semaphore itself is fine - the kernel released it when the holder died,\n  which is exactly why the buffer needed its own flag to look untrustworthy.\n",
    ));
}

$buffer->destroy();

experiment_note('nothing here is repaired, and that is the honest position: a slot half-written by a process that no longer exists cannot be reconstructed, and a buffer that pretends otherwise hands the consumer a message that was never sent.');
experiment_note('the sockets of phase 5 have none of these failure modes - not because they are better engineered, but because the kernel owns the buffer, the boundaries and the lifetime, and a peer that dies produces an EOF rather than a half-written record.');
experiment_note('every check above costs four bytes of header and one comparison. The expensive part of shared memory was never the checking; it is that there is nobody else to do it.');
experiment_context();
