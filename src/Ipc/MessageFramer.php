<?php

declare(strict_types=1);

namespace App\Ipc;

use App\Ipc\Exception\ChannelException;
use LengthException;

/**
 * Length-prefixed framing for messages on a byte stream.
 *
 * Every message travels as [4-byte big-endian length][payload]. A stream has
 * no message semantics of its own - one read() is not one message - so the
 * header is what lets a reader stop at exactly the right byte.
 *
 * Only the two halves a sender and a reader actually need live here: encode()
 * builds a frame, decodeHeader() reads the length off one. Reassembly is not
 * here on purpose, because only something holding the stream knows whether
 * the rest of the payload has arrived yet - that is SocketChannel's buffer.
 */
final readonly class MessageFramer
{
    public const HEADER_LENGTH = 4;

    /**
     * Upper bound on a single message, in both directions. Without it a
     * corrupt or hostile header - four bytes are enough to ask for 4 GiB -
     * becomes an allocation the process cannot survive.
     */
    public const MAX_MESSAGE_LENGTH = 64 * 1024 * 1024;

    /**
     * @throws LengthException when the payload exceeds MAX_MESSAGE_LENGTH
     */
    public static function encode(string $payload): string
    {
        $length = strlen($payload);

        if ($length > self::MAX_MESSAGE_LENGTH) {
            throw new LengthException(sprintf(
                'Payload of %d bytes exceeds the %d-byte frame limit',
                $length,
                self::MAX_MESSAGE_LENGTH,
            ));
        }

        return pack('N', $length) . $payload;
    }

    /**
     * @throws ChannelException when the header is not exactly HEADER_LENGTH bytes
     * @throws LengthException  when it declares a payload past the frame limit
     */
    public static function decodeHeader(string $header): int
    {
        if (strlen($header) !== self::HEADER_LENGTH) {
            throw new ChannelException(sprintf(
                'Frame header must be %d bytes, got %d',
                self::HEADER_LENGTH,
                strlen($header),
            ));
        }

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', $header);
        $length = $unpacked[1];

        if ($length > self::MAX_MESSAGE_LENGTH) {
            throw new LengthException(sprintf(
                'Frame declares a %d-byte payload, exceeding the %d-byte frame limit',
                $length,
                self::MAX_MESSAGE_LENGTH,
            ));
        }

        return $length;
    }
}
