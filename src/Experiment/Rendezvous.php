<?php

declare(strict_types=1);

namespace App\Experiment;

use RuntimeException;
use Socket;

/**
 * A one-shot meeting point between a parent and the children it forks.
 *
 * The forking experiments all have the same shape: a child gets itself into
 * the state being measured - wrote its pages, read its array, mapped its file
 * - and the parent takes its snapshot while that state still exists. They
 * used to arrange that with a pair of sleeps, the child's long enough to
 * outlast the parent's, which is a guess about scheduling that a loaded
 * machine is free to get wrong: the parent measures before the child has
 * done anything, or after it has already gone.
 *
 * A Unix socket pair replaces the guess with two facts. A child writes one
 * byte when it has arrived and then reads until EOF; the parent reads one
 * byte per child, measures, and closes its end, which is the EOF every child
 * is waiting for. Created before the fork, used once, and only between one
 * parent and its own children.
 */
final class Rendezvous
{
    /**
     * How long the parent waits for its children before calling it a failure.
     * Arriving takes milliseconds; this is for a child that died first.
     */
    private const ARRIVAL_TIMEOUT_SECONDS = 10;

    private function __construct(
        private ?Socket $parentEnd,
        private ?Socket $childEnd,
    ) {
    }

    /** Must be called before pcntl_fork(), so that both ends are inherited. */
    public static function create(): self
    {
        $sockets = [];

        if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets)) {
            throw new RuntimeException('socket_create_pair() failed: ' . socket_strerror(socket_last_error()));
        }

        /** @var array{Socket, Socket} $sockets */
        return new self($sockets[0], $sockets[1]);
    }

    /**
     * Child side: the state the parent is waiting to measure now exists.
     *
     * Also closes the child's copy of the parent's end, without which the
     * parent closing its own copy would never add up to EOF here.
     */
    public function arrive(): void
    {
        $this->closeParentEnd();

        if ($this->childEnd === null || @socket_write($this->childEnd, "\x01", 1) !== 1) {
            throw new RuntimeException('unable to tell the parent this child has arrived');
        }
    }

    /**
     * Child side: blocks until the parent calls release() - or exits, which
     * closes its end just the same and is as good a reason to stop waiting.
     */
    public function awaitRelease(): void
    {
        if ($this->childEnd === null) {
            throw new RuntimeException('awaitRelease() is the child side, after arrive()');
        }

        do {
            $read = @socket_read($this->childEnd, 1);
        } while ($read !== false && $read !== '');
    }

    /**
     * Parent side: blocks until $children children have called arrive().
     *
     * The parent's copy of the child end is closed first. After that, if
     * every child dies without arriving, the read sees EOF rather than
     * waiting forever, and a single child that dies while the others hold
     * their state is caught by the timeout.
     */
    public function awaitArrivals(int $children = 1): void
    {
        $this->closeChildEnd();

        if ($this->parentEnd === null) {
            throw new RuntimeException('awaitArrivals() is the parent side');
        }

        socket_set_option($this->parentEnd, SOL_SOCKET, SO_RCVTIMEO, [
            'sec' => self::ARRIVAL_TIMEOUT_SECONDS,
            'usec' => 0,
        ]);

        $arrived = 0;

        while ($arrived < $children) {
            $read = @socket_read($this->parentEnd, $children - $arrived);

            if ($read === false || $read === '') {
                throw new RuntimeException(sprintf(
                    '%d of %d children arrived; the rest exited or took longer than %d s',
                    $arrived,
                    $children,
                    self::ARRIVAL_TIMEOUT_SECONDS,
                ));
            }

            $arrived += strlen($read);
        }
    }

    /** Parent side: lets every waiting child continue. */
    public function release(): void
    {
        $this->closeParentEnd();
        $this->closeChildEnd();
    }

    private function closeParentEnd(): void
    {
        if ($this->parentEnd !== null) {
            socket_close($this->parentEnd);
            $this->parentEnd = null;
        }
    }

    private function closeChildEnd(): void
    {
        if ($this->childEnd !== null) {
            socket_close($this->childEnd);
            $this->childEnd = null;
        }
    }
}
