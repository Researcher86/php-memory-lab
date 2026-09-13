# Shared Memory: The Same Bytes, and Nobody in Charge

Phase 6 puts two processes in front of one region of memory and asks the
questions a socket never has to answer: who may write, when is a value whole,
and who cleans up after a process that is no longer there.

A socket copies. Shared memory does not — and everything difficult about it
follows from that one saving.

## PHP's SysV shared memory is not a shared byte buffer

`shm_put_var()` runs the value through `serialize()`, and `shm_get_var()`
builds a new PHP value out of the bytes. Two processes never share a zval;
they share a region that happens to contain a serialized description of one.

```text
$ make experiment ARGS="shm:lifecycle"
Stored counter=1, read it back, set the copy to 999, stored it separately.
  index 1 is still 1 - modifying what get() returned changed nothing shared.
  an object round-trips by value too: same class stdClass, same id 7, different instance
```

Three consequences, in ascending order of how much trouble they cause:

1. **Every access has a price.** A 5,000-row array costs ~0.4 ms to put and
   ~0.6 ms to get, against 0.00004 ms for the PHP copy of the same array
   (which only increments a refcount). Shared memory saves a copy between
   *processes* and pays for a serialization round trip at both ends.
2. **The limit is the serialized size**, not the PHP one, plus per-variable
   bookkeeping: a 4 KiB segment took a 3.75 KiB payload and refused the next
   one.
3. **A read-modify-write is three operations, not one.** That is the gap the
   next section falls into.

```text
A 5,000-row array (316.31 KiB serialized):
  put()    0.414 ms
  get()    0.600 ms
  a plain PHP copy of the same array: 0.000038 ms (refcount++, nothing moves)

A 4.00 KiB segment refused a 4.00 KiB payload; the largest it took was 3.75 KiB.
```

## The memory does not belong to PHP

Shared pages are charged to `RssShmem` in `/proc/self/status`. They are part
of RSS and no part of `memory_get_usage()`:

```text
Writing and reading 512.00 KiB through the segment:
  PHP usage +192 B   RSS +520.00 KiB   RssShmem +512.00 KiB
```

A process whose PHP memory graph is flat can be holding megabytes this way.
This is the first of several mechanisms in this lab — `mmap` and FFI are the
others — where the engine's counters and the OS's disagree because the memory
was never the engine's to begin with.

## Without a lock, a counter is not a counter

`$counter = $counter + 1` across processes is a read, an add and a write.
The value read is correct only if nobody writes between the read and the
write, and nothing arranges that:

```text
$ make experiment ARGS="shm:race"
children | expected |      unsynchronized | semaphore-protected
----------------------------------------------------------------
       1 |      500 |    500 (  0.0% lost) |    500 (   5.2 ms)
       2 |     1000 |    446 ( 55.4% lost) |   1000 (  21.5 ms)
       4 |     2000 |   1243 ( 37.9% lost) |   2000 (  52.2 ms)
       8 |     4000 |   counter destroyed |   4000 ( 110.7 ms)
      16 |     8000 |   counter destroyed |   8000 ( 198.2 ms)
```

Three things in that table are worth more than the percentages.

**One child is always correct.** With nobody to race against, the
unsynchronized code passes — which is how this class of bug survives a test
suite and appears the first time the pool is scaled up.

**The loss is silent.** No error, no warning, no exception: only a total
that does not add up. Nothing fails, so nothing can be retried.

**At eight writers the counter stops existing.** PHP keeps a directory of
variables inside the segment and rewrites an entry by removing and
re-inserting it; concurrent writers corrupt that bookkeeping, not just the
value. Shared memory does not degrade gracefully — past a certain amount of
contention it stops being a data structure at all.

## The lock turns processes back into a queue

Under a semaphore the total is exact by construction, and the measurement
moves to what that costs:

```text
$ make experiment ARGS="shm:counter"
4 children x 1000 increments, every one of them under the semaphore.
Counter: 4000, expected 4000 - exact
Wall clock: 88.1 ms for 4000 protected updates (45426 updates/s).

 child |        total |      waiting | share spent waiting
--------------------------------------------------------
     0 |      75.2 ms |      68.6 ms | 91.1%
     1 |      80.5 ms |      73.1 ms | 90.9%
```

Each child spends ~91% of its life waiting for the other three. Four
processes do not finish four times faster; they take turns. Whether that is
acceptable depends entirely on how much work happens inside the critical
section relative to how much happens outside it — and a critical section
containing a serialize/deserialize round trip is not a small one.

## Crash consistency: the kernel unwinds its own locks, and nothing else

PHP passes `SEM_UNDO` on every `sem_acquire()`, so the kernel records the
acquisition against the process and undoes it if that process dies. A lock
flag written into the segment by hand has no owner the kernel knows about:

```text
Holder SIGKILLed while holding two locks at once:
  SysV semaphore:       acquirable again - the kernel undid the acquisition
  flag in shared memory: still says "held by 1917" - every waiter would wait forever
```

This is the whole argument for using the semaphore the kernel offers rather
than a byte in the segment. (`sem_get()`'s `$auto_release` argument is a
weaker, separate mechanism — it releases at PHP's *request* shutdown, which
on CLI is process exit anyway. Process death is covered by `SEM_UNDO`
regardless of what is passed.)

What the kernel does *not* unwind is the data. A process killed between two
related writes leaves the segment half-updated, and the next reader has no
way to tell. Sockets have no equivalent problem: a message is written whole
or not at all, and a producer killed after `send()` returns loses nothing,
because the bytes are already the kernel's
([Phase 5](PHASES.md#phase-5--process-ipc-with-unix-sockets-)).

## The segment outlives everything that touched it

`detach()` drops this process's handle. The segment stays:

```text
After detach(): the segment is still there, now with 0 process(es) attached.
After a child attached and was SIGKILLed: segment still there (0 attached).
After an explicit destroy(): segment gone.
```

Only `shm_remove()` or a reboot takes a segment away. This is why `ipcs -m`
on a long-lived machine is full of segments nobody owns, why a crashed run
leaves state that the next run silently inherits when it attaches the same
key, and why every test and experiment here cleans up **by key** rather than
through an object — a detached wrapper cannot remove anything, because the
handle it would need is exactly what it gave up.

The same applies to semaphores (`sem_remove()`) and, in Phase 7, to a ring
buffer whose header lives in a segment that may already contain the
half-written state of a previous run.

## Against the alternatives

| | Unix socket (Phase 5) | SysV shared memory |
|---|---|---|
| Copying | two copies per message | none — but a serialize round trip at both ends |
| Boundaries | the frame is the boundary | none; a record is whatever both sides agree it is |
| Synchronization | the kernel serializes the stream | yours to build, or nothing happens |
| Backpressure | built in: a full buffer blocks the writer | none; a fast writer overwrites |
| Crash of a writer | nothing sent is lost | half-written records stay half-written |
| Cleanup | closing the last fd frees everything | explicit `shm_remove()` or it leaks |
| Addressing | the pair, inherited through `fork()` | a numeric key any process can guess |

Shared memory wins where the data is large and the access pattern is mostly
reads; the socket wins nearly everywhere else, and it wins by being harder to
get wrong. Phase 7 builds a ring buffer to find out how much machinery it
takes to get message boundaries and flow control back on top of a segment —
i.e. how much of the socket has to be rebuilt by hand. The answer is below.

## Phase 7: rebuilding the socket by hand

`RingBuffer` puts a fixed layout in front of a raw segment — a 32-byte header
(magic, version, capacity, slot size, read and write positions, count, busy
pid) and `capacity` slots of `[4-byte length][payload]` — and guards every
operation with a semaphore. That is message boundaries, ordering, a definition
of "full", backpressure and crash detection, all of which a socket has before
anyone writes a line.

The comparison against that socket is the phase's actual result:

```text
$ make experiment ARGS="ring:throughput"
  message | transport     |       wall |     messages/s |  throughput | CPU seconds
--------------------------------------------------------------------------------------
     64 B | ring + lock   |   934.9 ms |         21,392 |  1.31 MiB/s | 0.70
     64 B | unix socket   |    39.8 ms |        502,886 | 30.69 MiB/s | 0.05
     64 B | ring, no lock |    26.3 ms |        761,529 | 46.48 MiB/s | 0.05
 1.00 KiB | ring + lock   |   897.3 ms |         22,289 | 21.77 MiB/s | 0.66
 1.00 KiB | unix socket   |    31.4 ms |        636,561 | 621.64 MiB/s | 0.05
 1.00 KiB | ring, no lock |    26.8 ms |        746,357 | 728.86 MiB/s | 0.05
16.00 KiB | ring + lock   |   972.7 ms |         20,560 | 321.26 MiB/s | 0.74
16.00 KiB | unix socket   |    87.5 ms |        228,643 |  3.49 GiB/s | 0.14
16.00 KiB | ring, no lock |    44.7 ms |        447,296 |  6.83 GiB/s | 0.09
```

The third row is the control: the same segment, the same header, the same
slots, with the semaphore removed and correctness resting on there being
exactly one producer and one consumer. It is the fastest transport in the
table. So the shared memory is not the slow part — **the lock is**.

One acquire and one release per message, between two processes that both want
it constantly, means most acquires find it held. An acquire that has to wait
is a sleep and a wake: tens of microseconds against the ~8 µs the push and
pop themselves cost. The socket avoids this not by being cleverer but by
having the kernel do the synchronizing as a side effect of moving the bytes,
and charging once for both.

### Polling is a dial, and it has no good setting

`pop()` on an empty buffer returns `null`. There is nothing to wait on, so the
consumer has to come back and ask again:

```text
$ make experiment ARGS="ring:pacing"
200 messages, one every 2000 us, varying only how long the consumer waits between polls:

    poll gap |       wall |   mean latency | consumer CPU
------------------------------------------------------------
spin, no gap |   540.9 ms |        46.1 us |     531.4 ms
      100 us |   543.6 ms |       140.9 us |      36.1 ms
     1000 us |   464.7 ms |       630.6 us |       9.3 ms
    10000 us |   563.7 ms |      6464.2 us |       6.4 ms
```

Spinning buys 46 µs of latency for a whole CPU core. A 10 ms gap gives the
core back and costs 140× the latency. A socket has no such dial: the reader
sleeps in `recv()` and the kernel wakes it exactly when there is something to
read. Closing that gap in shared memory means adding something to be woken on
— an eventfd, a futex, a semaphore used as a signal — and that is a syscall
per message again, which is where the socket already was.

The refusal to overwrite is the other half. A full buffer returns `false`, the
producer has to wait, and it therefore runs at the consumer's rate without
either side agreeing to it. That is the same backpressure a blocked `send()`
applies on a socket, built out of eight slots and a comparison.

### Failure modes, and which of them can be checked

```text
$ make experiment ARGS="ring:failures"
  a key with nothing behind it         No ring buffer at 0x70ebae26
  a segment holding other data         Segment 0x779ae51a is not a ring buffer (magic 0x07070707, ...)
  a segment too small for a header     Segment 0x5478fbeb is too small to hold a header
  a buffer written by a newer version  Ring buffer 0x7a6d0e6c is version 2, this code speaks version 1
  a message larger than a slot         Message of 17 bytes exceeds the 16-byte slot

A producer SIGKILLed while it held the lock:
  caught on attempt 8: header still names pid 214 as mid-update
  pop() refuses: Ring buffer 0x7f7684e8 was left mid-update by pid 214
```

The first four are cheap: a key names a region of bytes and nothing in it says
who formatted it, so magic and version are four bytes each and a comparison.
The last one cannot be checked in advance. The kernel releases the semaphore
of a process that dies, so the next process gets a *clean lock over a dirty
buffer* — the busy pid in the header is the only thing that distinguishes it
from a healthy one. It is detected, never repaired: a slot half-written by a
process that no longer exists cannot be reconstructed, and a buffer that
pretends otherwise hands the consumer a message that was never sent.

### What the phase actually settles

Shared memory saves a copy. Making it safe costs a lock, and the lock costs
more than the copy — at least at one lock per message, which is the shape most
uses take. Shared memory wins where that ratio inverts: large payloads, mostly
reads, or many messages per acquisition. It loses where messages are small and
frequent, which is most of what a worker pool does.

Everything after this is a way of not paying the lock: batching, lock-free
single-producer designs that give up crash detection, or a kernel primitive
that combines the wakeup with the handoff — at which point the socket is
already the answer.

## Practical rules

1. **Never touch a shared value without a lock**, including a single
   increment, including a read that will be written back.
2. **Hold the lock for the smallest section that is still correct** — and
   remember serialization happens inside it.
3. **Release in a `finally`.** `Semaphore::synchronized()` exists so the
   release cannot be forgotten on the failure path.
4. **Clean up by key.** Resources outlive objects, processes and crashes.
5. **Treat an existing segment as suspect.** Attaching a key that already
   exists inherits whatever the last run left, including a half-written
   record.
6. **Measure before choosing it over a socket.** The copy that shared memory
   saves is often smaller than the serialization it adds — and much smaller
   than the synchronization it needs.
7. **Count the locks per message.** One acquire and one release per small
   message is the shape that loses to a socket by an order of magnitude.

## Reproduce

```bash
make experiment ARGS="shm:lifecycle"     # serialization, size limits, RssShmem, stale segments
make experiment ARGS="shm:race"          # lost updates and a destroyed counter at 1…16 writers
make experiment ARGS="shm:counter"       # the protected version, and what the lock costs
make experiment ARGS="ring:throughput"   # ring buffer vs socket vs the same ring with no lock
make experiment ARGS="ring:pacing"       # full, empty, backpressure, and the polling dial
make experiment ARGS="ring:failures"     # bad magic, bad version, a writer killed mid-update
```
