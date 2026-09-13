# Choosing an IPC Mechanism: What Each One Actually Costs

Phases 5 to 9 each built one way for two PHP processes to exchange data. This
document is the comparison across all of them — what each one is for, what it
copies, what it makes you synchronize, and what it does when a process dies.

Every number here comes from an experiment in this repository and describes
the machine that ran it. See [BENCHMARKS.md](BENCHMARKS.md).

## The table

| | Unix socket | SysV message queue | SysV shared memory | `mmap` (`MAP_SHARED`) | FFI buffer |
|---|---|---|---|---|---|
| **Built here** | [Phase 5](PHASES.md) | no — see below | [Phase 6](PHASES.md) | [Phase 8](PHASES.md) | [Phase 9](PHASES.md) |
| **Unit** | a stream of bytes | a message | a region of bytes | a region of bytes | a region of bytes |
| **Boundaries** | you add them (a length prefix) | the kernel keeps them | none | none | none |
| **Copies per message** | two (in and out of the kernel) | two | none, plus a serialize round trip | none | none |
| **Ordering** | guaranteed by the stream | FIFO per type | yours to arrange | yours to arrange | n/a |
| **Synchronization** | the kernel serializes the stream | the kernel | yours (semaphore) | yours (semaphore) | single-process |
| **Backpressure** | built in — a full buffer blocks the writer | built in — a full queue blocks | none; a fast writer overwrites | none | n/a |
| **Blocking wait** | `recv()` sleeps until there is data | `msgrcv()` does | polling, or build a signal | polling | n/a |
| **A writer dies** | nothing sent is lost; reader sees EOF | same | half-written records stay half-written | same | the allocation leaks |
| **Cleanup** | closing the last fd frees everything | `ipcrm`, or it leaks | `shm_remove()`, or it leaks | `unlink()` the file | `free()`, or it leaks |
| **Addressing** | a pair, or a path in the filesystem | a numeric key | a numeric key | a path in the filesystem | a pointer |
| **Visible in** | `lsof` | `ipcs -q` | `ipcs -m`, `RssShmem` | `ls`, `RssFile` | RSS only |
| **`memory_get_usage()` sees it** | the payload, as a PHP string | the payload | no | no | no |

The SysV message queue is the one mechanism this lab did not build. It is the
socket's semantics — framed messages, ordering, a blocking receive,
backpressure from a bounded queue — with a numeric key instead of a file
descriptor, and therefore with `ipcs -q` cleanup and no `select()`. Having
built the socket, there was nothing left for it to demonstrate that the
socket had not, and a mechanism in this project has to earn its experiment.

## The measurement that decides most of it

The intuition going in was that shared memory is fast because it avoids a
copy, and that a socket is the convenient but slower option. The ring buffer
of [Phase 7](PHASES.md) was built to
quantify that, and it came out the other way round:

```text
$ make experiment ARGS="ring:throughput"
     64 B | ring + lock   |   934.9 ms |  21,392 msg/s | 0.70 CPU s
     64 B | unix socket   |    39.8 ms | 502,886 msg/s | 0.05 CPU s
     64 B | ring, no lock |    26.3 ms | 761,529 msg/s | 0.05 CPU s
```

The third row is the same segment, header and slots with the semaphore
removed. Shared memory really is the fastest transport here — and adding the
synchronization that makes it safe costs more than the copy it saved. One
acquire and one release per message, between two processes that both want the
lock constantly, means most acquires find it held, and an acquire that waits
is a sleep and a wake: tens of microseconds against the ~8 µs the operation
itself takes.

The socket wins not by being cleverer but by having the kernel do the
synchronizing as a side effect of moving the bytes, and charge once for both.

## So: which one

**Use a Unix socket** unless you can name the reason not to. It has message
boundaries once you add four bytes, ordering, backpressure, a blocking wait
that costs no CPU, and a cleanup story that is "close the descriptor". Nothing
below it is cheap enough to be worth its failure modes for messages of
ordinary size.

**Use shared memory** when the data is large and mostly read — a lookup table,
a cache, a page of state a dozen workers consult and rarely write. The copy
it saves scales with the payload; the lock it needs does not scale with
anything, so the ratio only improves as the data grows. Bring a semaphore, and
count the acquisitions per message before believing any estimate.

**Use `mmap`** when the data is large, mostly read, and wants to outlive the
processes or be inspectable from outside. It is shared memory with a filename:
the same raw bytes, minus the serialization PHP's SysV wrapper imposes, with a
lifetime the filesystem manages instead of `ipcs`. It is also the only one of
these that can be larger than RAM.

**Use an FFI buffer** when the memory is not communication at all — a region
to hand to a C library, or a large mutable buffer PHP's string semantics make
expensive. It is not IPC; it is in this table because it is the fourth way to
hold bytes and the third way to make memory the PHP counters cannot see.

## What each one does when something goes wrong

This is usually the deciding factor, and it is where the mechanisms differ
most.

**A socket writer that dies** loses nothing it has already sent. Once
`send()` returns, the bytes belong to the kernel buffer, which outlives the
process — measured by SIGKILLing a producer after five sends and reading all
five:

```text
$ make experiment ARGS="ipc:backpressure"
Producer SIGKILLed after 5 sends: consumer read 5 of them, then saw "Peer closed the channel".
```

**A shared-memory writer that dies** leaves whatever it was in the middle of.
The kernel releases its semaphore — PHP passes `SEM_UNDO` on every acquire —
so the next process gets a clean lock over a dirty buffer, and only an
explicit flag in the data distinguishes the two. The ring buffer's header
carries the pid of whoever is mid-update for exactly this reason, and the
result is detected and never repaired: a slot half-written by a process that
no longer exists cannot be reconstructed.

**A shared-memory writer that does not die** is a hazard on its own. Without a
lock, the same counter loses updates silently and then stops existing:

```text
$ make experiment ARGS="shm:race"
       2 |     1000 |    446 ( 55.4% lost) |   1000
       8 |     4000 |   counter destroyed  |   4000
```

**A leaked resource** differs in how loudly. A closed socket takes its buffers
with it. A shared-memory segment survives every process that touched it until
`shm_remove()` or a reboot, which is why `ipcs -m` on a long-lived machine is
full of segments nobody owns. An FFI allocation whose pointer was dropped is
unreachable for the life of the process, and moves `memory_get_usage()` by
nothing at all.

## Serialization is a separate decision

Whatever the transport, something has to turn a PHP value into bytes, and that
cost is often larger than the transport's:

```text
$ make experiment ARGS="ipc:serialization"
json       | 112.37 KiB |   0.26 ms encode   0.77 ms decode
serialize  | 184.04 KiB |   0.21 ms          0.31 ms
csv        |  52.31 KiB |   0.33 ms          0.38 ms
binary     |  52.52 KiB |   0.93 ms          5.56 ms
raw string |  97.66 KiB |       n/a              n/a
```

`serialize()` is the fastest round trip and the largest payload. The packed
binary format halves JSON's bytes and is the slowest to decode, because
unpacking field by field runs in userland while `json_decode()` is one C call
over the whole payload. Fewer bytes earn their cost when the wire is the
bottleneck — a network, a full socket buffer — and not otherwise.

Two of these mechanisms make the decision for you. PHP's SysV shared memory
serializes every value on the way in and deserializes it on the way out, so a
"zero-copy" segment costs a `serialize()` round trip per access; the benchmark
puts those two costs within a few percent of each other. `mmap` and FFI
buffers do not, which is part of why they are the raw-bytes options.

## Reproduce

```bash
make experiment ARGS="ipc:socket"          # round-trip latency and throughput, 0 B to 10 MiB
make experiment ARGS="ipc:backpressure"    # a full buffer, and a producer killed mid-run
make experiment ARGS="ipc:serialization"   # what the payload costs before it reaches the transport
make experiment ARGS="shm:race"            # what shared memory does without a lock
make experiment ARGS="ring:throughput"     # all three transports on the same exchange
make benchmark  ARGS="ipc"                 # the same mechanisms, single-process and uncontended
```
