<?php

declare(strict_types=1);

use App\Benchmark\Benchmark;
use App\Ipc\RingBuffer;
use App\Ipc\Semaphore;
use App\Ipc\SharedMemorySegment;
use App\Ipc\SocketChannel;

/**
 * The three transports of phases 5 to 7, measured against each other on the
 * same payload.
 *
 * Single-process on purpose. A round trip between two processes measures the
 * scheduler as much as the transport - that comparison has its own experiment
 * (ring:throughput) - while this suite isolates the cost of the mechanism
 * itself: framing and two copies for the socket, a lock and a memcpy for the
 * ring buffer, a serialize round trip for the segment.
 *
 * Resources are created once, outside the measured closures, and released by
 * the shutdown function - a benchmark that leaks a segment per iteration
 * would measure the leak.
 *
 * @return list<Benchmark>
 */
$payload = str_repeat('x', 1024);

[$left, $right] = SocketChannel::pair();
$ring = RingBuffer::create(SharedMemorySegment::randomKey(), 64, 1024);
$segment = SharedMemorySegment::attach(SharedMemorySegment::randomKey());
$semaphore = Semaphore::attach(SharedMemorySegment::randomKey());
$rows = array_fill(0, 100, ['id' => 1, 'name' => 'user', 'tags' => ['a', 'b']]);

register_shutdown_function(static function () use ($left, $right, $ring, $segment, $semaphore): void {
    $left->close();
    $right->close();
    $ring->destroy();
    $segment->destroy();
    $semaphore->remove();
});

return [
    new Benchmark('socket: 1 KiB send+receive', 20_000, static function () use ($left, $right, $payload): void {
        $left->send($payload);
        $right->receive();
    }),

    new Benchmark('ring buffer: 1 KiB push+pop', 20_000, static function () use ($ring, $payload): void {
        $ring->push($payload);
        $ring->pop();
    }),

    new Benchmark('semaphore: acquire+release', 20_000, static function () use ($semaphore): void {
        $semaphore->acquire();
        $semaphore->release();
    }),

    new Benchmark('shm: put+get 1 KiB', 20_000, static function () use ($segment, $payload): void {
        $segment->put(1, $payload);
        $segment->get(1);
    }),

    new Benchmark('shm: put+get 100 rows', 2_000, static function () use ($segment, $rows): void {
        $segment->put(2, $rows);
        $segment->get(2);
    }),

    new Benchmark('serialize: 100 rows round trip', 2_000, static function () use ($rows): void {
        unserialize(serialize($rows), ['allowed_classes' => false]);
    }),

    new Benchmark('json: 100 rows round trip', 2_000, static function () use ($rows): void {
        json_decode((string) json_encode($rows, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }),
];
