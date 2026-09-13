<?php

declare(strict_types=1);

namespace App\Ipc\Exception;

/**
 * The channel has no peer any more: an EOF while reading, a refused write, or
 * an attempt to use an endpoint that was already closed. Nothing about it can
 * recover - a channel is a pair, and one half of it is gone.
 */
final class ChannelClosedException extends ChannelException
{
}
