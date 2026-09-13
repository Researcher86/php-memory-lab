<?php

declare(strict_types=1);

namespace App\Ipc\Exception;

/**
 * The bytes in the segment do not describe a usable ring buffer: wrong magic,
 * a version this code does not speak, a slot whose declared length cannot be
 * true, or a header left mid-update by a process that died.
 *
 * Never recoverable by retrying. Shared memory has no transaction to roll
 * back, so the only honest responses are to refuse the buffer or to format a
 * new one and accept that its contents are gone.
 */
final class RingBufferCorruptedException extends RingBufferException {}
