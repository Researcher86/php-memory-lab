<?php

declare(strict_types=1);

namespace App\Tests\Ipc;

use App\Ipc\Exception\ChannelException;
use App\Ipc\MessageFramer;
use LengthException;
use PHPUnit\Framework\TestCase;

final class MessageFramerTest extends TestCase
{
    public function testEncodePrefixesTheBigEndianLength(): void
    {
        self::assertSame("\x00\x00\x00\x05hello", MessageFramer::encode('hello'));
    }

    public function testEmptyPayloadStillGetsAHeader(): void
    {
        self::assertSame("\x00\x00\x00\x00", MessageFramer::encode(''));
    }

    public function testEncodeRejectsAPayloadPastTheFrameLimit(): void
    {
        $this->expectException(LengthException::class);

        MessageFramer::encode(str_repeat('x', MessageFramer::MAX_MESSAGE_LENGTH + 1));
    }

    public function testDecodeHeaderReadsTheLengthBackBigEndian(): void
    {
        self::assertSame(1_000_000, MessageFramer::decodeHeader("\x00\x0f\x42\x40"));
    }

    public function testHeaderAndLengthAreInverses(): void
    {
        $frame = MessageFramer::encode(str_repeat('y', 70_000));

        self::assertSame(70_000, MessageFramer::decodeHeader(substr($frame, 0, MessageFramer::HEADER_LENGTH)));
    }

    /**
     * Four bytes can ask for 4 GiB. Honouring that on a corrupt or hostile
     * header is an allocation the process does not survive.
     */
    public function testDecodeHeaderRejectsALengthPastTheFrameLimit(): void
    {
        $this->expectException(LengthException::class);

        MessageFramer::decodeHeader("\xff\xff\xff\xff");
    }

    public function testDecodeHeaderRejectsAnIncompleteHeader(): void
    {
        $this->expectException(ChannelException::class);

        MessageFramer::decodeHeader("\x00\x00");
    }
}
