<?php

declare(strict_types=1);

namespace App\Ipc\Exception;

use RuntimeException;

/**
 * The ring buffer refused an operation it understood: a message larger than a
 * slot, a segment that could not be created, an already destroyed buffer.
 */
class RingBufferException extends RuntimeException
{
}
