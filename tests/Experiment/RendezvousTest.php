<?php

declare(strict_types=1);

namespace App\Tests\Experiment;

use App\Experiment\Rendezvous;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RendezvousTest extends TestCase
{
    /**
     * The whole point: when awaitArrivals() returns, every child is there,
     * and stays there until it is released - which is the window a parent's
     * snapshot needs.
     */
    public function testChildrenHoldUntilReleasedAndThenFinish(): void
    {
        $rendezvous = Rendezvous::create();
        $pids = [];

        for ($i = 0; $i < 3; $i++) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);

            if ($pid === 0) {
                $rendezvous->arrive();
                $rendezvous->awaitRelease();

                exit(0);
            }

            $pids[] = $pid;
        }

        $rendezvous->awaitArrivals(3);

        foreach ($pids as $pid) {
            self::assertSame(0, pcntl_waitpid($pid, $status, WNOHANG), 'a child left before it was released');
        }

        $rendezvous->release();

        foreach ($pids as $pid) {
            self::assertSame($pid, pcntl_waitpid($pid, $status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }
    }

    /** A child that dies before arriving is an error, not a hang. */
    public function testAChildThatNeverArrivesFailsTheWait(): void
    {
        $rendezvous = Rendezvous::create();

        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);

        if ($pid === 0) {
            exit(0);
        }

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('0 of 1 children arrived');

            $rendezvous->awaitArrivals(1);
        } finally {
            pcntl_waitpid($pid, $status);
        }
    }
}
