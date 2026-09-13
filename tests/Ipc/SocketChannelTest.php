<?php

declare(strict_types=1);

namespace App\Tests\Ipc;

use App\Ipc\Exception\ChannelClosedException;
use App\Ipc\MessageFramer;
use App\Ipc\SocketChannel;
use PHPUnit\Framework\TestCase;
use Socket;

final class SocketChannelTest extends TestCase
{
    /** @var list<SocketChannel> */
    private array $channels = [];

    /** @var list<Socket> */
    private array $rawSockets = [];

    protected function tearDown(): void
    {
        foreach ($this->channels as $channel) {
            $channel->close();
        }

        foreach ($this->rawSockets as $socket) {
            @socket_close($socket);
        }

        $this->channels = [];
        $this->rawSockets = [];
    }

    public function testMessagesTravelInBothDirections(): void
    {
        [$a, $b] = $this->pair();

        $a->send('ping');
        $b->send('pong');

        self::assertSame('ping', $b->receive());
        self::assertSame('pong', $a->receive());
    }

    public function testSendReturnsTheFrameSizeNotThePayloadSize(): void
    {
        [$a] = $this->pair();

        self::assertSame(MessageFramer::HEADER_LENGTH + 5, $a->send('hello'));
    }

    public function testEmptyPayloadSurvivesTheRoundTrip(): void
    {
        [$a, $b] = $this->pair();

        $a->send('');

        self::assertSame('', $b->receive());
    }

    /**
     * Three sends can land in the reader as one recv(). Without framing the
     * reader would hand back all three glued together.
     */
    public function testThreeFramesWrittenBackToBackArriveSeparately(): void
    {
        [$a, $b] = $this->pair();

        $a->send('first');
        $a->send('second');
        $a->send('third');

        self::assertSame('first', $b->receive());
        self::assertSame('second', $b->receive());
        self::assertSame('third', $b->receive());
    }

    /**
     * The mirror case: 64 KiB needs several 16 KiB reads, so the frame is
     * only complete after the buffer has been refilled a few times. It stays
     * under the kernel socket buffer on purpose - a bigger payload would
     * block this single-process test against itself.
     */
    public function testAFrameSpanningSeveralReadsIsReassembled(): void
    {
        [$a, $b] = $this->pair();
        $payload = str_repeat('p', 64 * 1024);

        $a->send($payload);

        self::assertSame($payload, $b->receive());
    }

    public function testReceiveOrNullReturnsNullWhenNothingArrived(): void
    {
        [, $b] = $this->pair();

        self::assertNull($b->receiveOrNull());
    }

    public function testReceiveOrNullTreatsAPartialFrameAsNoMessage(): void
    {
        [$raw, $channel] = $this->halfRawPair();

        socket_send($raw, "\x00\x00\x00\x05he", 6, 0);
        self::assertNull($channel->receiveOrNull());

        socket_send($raw, 'llo', 3, 0);
        self::assertSame('hello', $channel->receive());
    }

    public function testReceiveReportsACleanClose(): void
    {
        [$a, $b] = $this->pair();
        $a->close();

        $this->expectException(ChannelClosedException::class);
        $this->expectExceptionMessage('Peer closed the channel');

        $b->receive();
    }

    public function testReceiveReportsACloseThatLeftAFrameUnfinished(): void
    {
        [$raw, $channel] = $this->halfRawPair();

        socket_send($raw, "\x00\x00\x00\x05he", 6, 0);
        socket_close($raw);
        $this->rawSockets = [];

        $this->expectException(ChannelClosedException::class);
        $this->expectExceptionMessage('Peer closed mid-frame');

        $channel->receive();
    }

    public function testSendingToADepartedPeerThrowsInsteadOfKillingTheProcess(): void
    {
        [$a, $b] = $this->pair();
        $b->close();

        $this->expectException(ChannelClosedException::class);

        // Twice: the first write may be swallowed by the kernel buffer, and
        // only the second one meets the EPIPE that MSG_NOSIGNAL turns into an
        // error return rather than a SIGPIPE.
        $a->send('late');
        $a->send('later');
    }

    public function testAClosedChannelRefusesFurtherIo(): void
    {
        [$a] = $this->pair();
        $a->close();

        self::assertFalse($a->isOpen());

        $this->expectException(ChannelClosedException::class);

        $a->send('anything');
    }

    /**
     * The case the channel exists for: both endpoints survive a fork(), and
     * each process drops the end it does not own so that EOF stays meaningful.
     */
    public function testTheChannelCarriesMessagesAcrossAFork(): void
    {
        [$parent, $child] = SocketChannel::pair();

        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);

        if ($pid === 0) {
            $parent->close();
            $child->send(strtoupper($child->receive()));
            $child->close();

            exit(0);
        }

        $child->close();
        $this->channels[] = $parent;

        $parent->send('ping');
        self::assertSame('PING', $parent->receive());

        pcntl_waitpid($pid, $status);
        self::assertSame(0, pcntl_wexitstatus($status));
    }

    /**
     * @return array{SocketChannel, SocketChannel}
     */
    private function pair(): array
    {
        [$a, $b] = SocketChannel::pair();

        $this->channels[] = $a;
        $this->channels[] = $b;

        return [$a, $b];
    }

    /**
     * A pair with only one end wrapped, so a test can put arbitrary bytes -
     * half a frame, a corrupt header - on the wire.
     *
     * @return array{Socket, SocketChannel}
     */
    private function halfRawPair(): array
    {
        $sockets = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);

        /** @var array{Socket, Socket} $sockets */
        $channel = new SocketChannel($sockets[1]);

        $this->rawSockets[] = $sockets[0];
        $this->channels[] = $channel;

        return [$sockets[0], $channel];
    }
}
