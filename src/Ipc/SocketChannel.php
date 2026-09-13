<?php

declare(strict_types=1);

namespace App\Ipc;

use App\Ipc\Exception\ChannelClosedException;
use App\Ipc\Exception\ChannelException;
use Socket;

/**
 * A message channel over one end of a connected Unix socket pair.
 *
 * The pair is the fork primitive of this lab: socket_create_pair() hands back
 * two connected endpoints, pcntl_fork() duplicates both into the child, and
 * each process closes the end it does not own so that the survivor's reads
 * can ever see EOF.
 *
 * What the class adds over the raw socket is message boundaries. A stream
 * delivers bytes, not messages: one recv() may carry three frames at once,
 * and one frame may need three recv() calls. Both cases are absorbed by a
 * single reassembly buffer, and every call hands out exactly one complete
 * message - or nothing at all.
 *
 * Waiting is the caller's choice: receive() blocks until a whole frame is
 * there, receiveOrNull() polls and returns null when it is not. Sending
 * always blocks, which is not an oversight - that block IS the backpressure
 * a full kernel buffer applies to a producer outrunning its consumer.
 */
final class SocketChannel
{
    /**
     * Bytes to ask for per recv(). Small reads pay for syscalls; large ones
     * make a single poll copy more than the one frame it was after. 16 KiB
     * sits between the two and covers most messages in one call.
     */
    private const READ_CHUNK_SIZE = 16 * 1024;

    /** Set by close(): our own descriptor is gone. */
    private bool $closed = false;

    /** Set on EOF or a refused write: the peer's descriptor is gone. */
    private bool $peerGone = false;

    /** Bytes pulled off the wire that do not yet add up to a frame. */
    private string $buffer = '';

    public function __construct(private readonly Socket $socket) {}

    /**
     * Two connected endpoints, ready to be split across a fork().
     *
     * @return array{self, self}
     */
    public static function pair(): array
    {
        $sockets = [];

        if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets)) {
            throw new ChannelException(
                'socket_create_pair() failed: ' . socket_strerror(socket_last_error()),
            );
        }

        /** @var array{Socket, Socket} $sockets */
        return [new self($sockets[0]), new self($sockets[1])];
    }

    /**
     * Writes one message and returns the number of frame bytes sent (payload
     * plus header). Blocks while the kernel buffer is full.
     *
     * @throws ChannelClosedException when the peer is no longer reading
     */
    public function send(string $payload): int
    {
        $this->assertUsable();

        $frame = MessageFramer::encode($payload);
        $length = \strlen($frame);
        $sent = 0;

        // Held in a variable because PHPStan's socket_send() stub lists the
        // flags it knows and MSG_NOSIGNAL is not among them, though PHP
        // defines it and the kernel honours it.
        $noSignal = \MSG_NOSIGNAL;

        while ($sent < $length) {
            // A stream socket may accept fewer bytes than offered, so the
            // write has to be resumed rather than assumed complete.
            // MSG_NOSIGNAL keeps a write to a departed peer from raising
            // SIGPIPE, whose default action would kill this process before
            // the error below could be reported.
            $written = @socket_send($this->socket, substr($frame, $sent), $length - $sent, $noSignal);

            if ($written === false || $written === 0) {
                $this->peerGone = true;

                throw new ChannelClosedException(\sprintf(
                    'Peer stopped reading after %d of %d frame bytes',
                    $sent,
                    $length,
                ));
            }

            $sent += $written;
        }

        return $sent;
    }

    /**
     * Waits for one complete message.
     *
     * @throws ChannelClosedException when the peer closed the stream
     */
    public function receive(): string
    {
        $this->assertUsable();

        while (true) {
            $frame = $this->takeFrame();

            if ($frame !== null) {
                return $frame;
            }

            $this->readChunk();
        }
    }

    /**
     * Returns one message if a complete one is already available, otherwise
     * null. Never waits, so a caller can pump several channels in turn.
     *
     * @throws ChannelClosedException when the peer closed the stream
     */
    public function receiveOrNull(): ?string
    {
        $this->assertUsable();

        $frame = $this->takeFrame();

        if ($frame !== null) {
            return $frame;
        }

        if (!$this->isReadable()) {
            return null;
        }

        $this->readChunk();

        // Still possibly null: the chunk may have been a fragment of a frame
        // whose tail is still in flight. A partial frame is not a message.
        return $this->takeFrame();
    }

    /**
     * Size of the kernel send buffer in bytes - the invisible queue behind
     * send(). A producer only blocks once it has filled this and the peer's
     * receive buffer, which is what makes socket backpressure measurable.
     */
    public function sendBufferSize(): int
    {
        $size = socket_get_option($this->socket, SOL_SOCKET, SO_SNDBUF);

        if (!\is_int($size)) {
            throw new ChannelException(
                'socket_get_option(SO_SNDBUF) failed: ' . socket_strerror(socket_last_error($this->socket)),
            );
        }

        return $size;
    }

    /** Idempotent, so that closing an already-EOF channel is still safe. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->buffer = '';

        socket_close($this->socket);
    }

    public function isOpen(): bool
    {
        return !$this->closed && !$this->peerGone;
    }

    /**
     * Takes the next complete frame off the reassembly buffer, or null while
     * the buffer holds less than one whole frame.
     */
    private function takeFrame(): ?string
    {
        if (\strlen($this->buffer) < MessageFramer::HEADER_LENGTH) {
            return null;
        }

        $length = MessageFramer::decodeHeader(substr($this->buffer, 0, MessageFramer::HEADER_LENGTH));
        $frameLength = MessageFramer::HEADER_LENGTH + $length;

        if (\strlen($this->buffer) < $frameLength) {
            return null;
        }

        $payload = substr($this->buffer, MessageFramer::HEADER_LENGTH, $length);
        $this->buffer = substr($this->buffer, $frameLength);

        return $payload;
    }

    /**
     * Appends the next chunk from the socket to the reassembly buffer,
     * blocking until something arrives.
     *
     * @throws ChannelClosedException when the peer closed the stream
     */
    private function readChunk(): void
    {
        $chunk = null;
        $received = @socket_recv($this->socket, $chunk, self::READ_CHUNK_SIZE, 0);

        if ($received === false) {
            throw new ChannelException(
                'socket_recv() failed: ' . socket_strerror(socket_last_error($this->socket)),
            );
        }

        if ($received === 0) {
            $this->peerGone = true;

            // Whether the buffer is empty separates a peer that finished from
            // one that died: a leftover fragment is a message nobody will
            // ever complete.
            throw new ChannelClosedException(
                $this->buffer === '' ? 'Peer closed the channel' : 'Peer closed mid-frame',
            );
        }

        $this->buffer .= (string) $chunk;
    }

    private function isReadable(): bool
    {
        $read = [$this->socket];
        $write = [];
        $except = [];

        $ready = @socket_select($read, $write, $except, 0, 0);

        if ($ready === false) {
            throw new ChannelException(
                'socket_select() failed: ' . socket_strerror(socket_last_error($this->socket)),
            );
        }

        return $ready > 0;
    }

    private function assertUsable(): void
    {
        if ($this->closed) {
            throw new ChannelClosedException('Attempted I/O on a closed channel');
        }

        if ($this->peerGone) {
            throw new ChannelClosedException('Attempted I/O on a channel whose peer is gone');
        }
    }
}
