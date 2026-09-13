<?php

declare(strict_types=1);

namespace App\Ipc\Exception;

use RuntimeException;

/**
 * Base for every failure the IPC layer reports. Thrown directly only for
 * errors a caller cannot act on differently - a failed syscall on a channel
 * that was, as far as anyone knew, healthy.
 */
class ChannelException extends RuntimeException {}
