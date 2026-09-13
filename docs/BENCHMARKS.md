# Benchmarks: How They Are Run, and How to Read Them

Phase 10 turns the ad-hoc timing loops scattered through the experiments into
one harness, so that any two numbers in this project can be compared without
first checking how each was produced.

```bash
make benchmark ARGS="memory"
make benchmark ARGS="ipc --format=json --output=var/results/ipc.json"
make benchmark ARGS="native --iterations=5000 --repetitions=9"
```

Three suites live in `benchmarks/`: `memory` (the PHP containers of Phase 2),
`ipc` (the transports of Phases 5–7), and `native` (the buffers of Phases
8–9).

## What the harness does

Two loops, and the difference between them is the whole design.

- The **inner** loop runs the operation `iterations` times and is what gets
  timed. The count belongs to the benchmark, not to the run: a socket round
  trip needs twenty thousand iterations to rise above timer noise, and
  allocating a 100,000-element array needs fifty.
- The **outer** loop repeats that whole measurement `repetitions` times, so
  there is a distribution rather than a single number. A benchmark that
  reports one timing cannot tell a fast operation from a lucky one.

**Warm-up repetitions run first and are discarded.** The first run of anything
here measures something else: OPcache compiling the closure, the allocator
growing an arena, the kernel faulting in pages, a socket buffer being sized.

**`ops/sec` is derived from the median**, not the mean. One repetition that
lost its CPU to something else should not decide the headline number — and the
`min`/`max` columns are there so a reader can see how much to trust it.

**Both memory views are recorded.** `PHP` is the engine's allocator and `RSS`
is the operating system's. A benchmark of anything below the engine — shared
memory, a mapping, an FFI buffer — moves only the second, and a result
reporting just the first would say the operation was free.

## Every report carries its machine

```text
Benchmark suite: ipc
PHP 8.5.10 on Linux 7.0.12-linuxkit, aarch64 x 12, memory_limit 128M, cgroup unlimited
OPcache off, JIT off, allocator Zend MM
```

A benchmark without its environment is not a measurement, it is an anecdote.
These particular fields are recorded because they are the ones that explain
the same code producing a different number somewhere else: JIT and OPcache
change how the loop itself executes, the allocator changes what
`memory_get_usage()` even means, and the cgroup limit decides whether the page
cache was there to be used.

The JSON output carries the same block as an object; CSV has nowhere to put a
header, so the fields that most often explain a difference are repeated on
every row.

## Sample results

From one run in the project's container. **These are not universal results** —
they describe the machine in the header above and nothing else.

```text
$ make benchmark ARGS="ipc"
benchmark                      |    iters |     median | ops/sec
--------------------------------------------------------------------
socket: 1 KiB send+receive     |   20,000 |   59.344 ms | 337,018
ring buffer: 1 KiB push+pop    |   20,000 |  200.867 ms |  99,568
semaphore: acquire+release     |   20,000 |   22.603 ms | 884,835
shm: put+get 1 KiB             |   20,000 |   19.912 ms | 1,004,409
shm: put+get 100 rows          |    2,000 |   53.356 ms |  37,484
serialize: 100 rows round trip |    2,000 |   51.466 ms |  38,860
json: 100 rows round trip      |    2,000 |  105.909 ms |  18,884
```

Two things in that table are worth more than the ranking. The shared-memory
put/get of a 100-row structure costs almost exactly what serializing it costs
— the segment is doing nothing else, which is the measurement behind
[shared-memory.md](shared-memory.md)'s claim that PHP's SysV shared memory is
a serialization API with a shared buffer attached. And the ring buffer,
single-process and uncontended, is slower than the socket purely from its two
semaphore operations per message — the same conclusion the two-process
experiment reaches by a different route.

```text
$ make benchmark ARGS="native"
benchmark                      |    iters |     median | ops/sec
--------------------------------------------------------------------
FFI buffer: write 64 KiB       |   20,000 |   56.690 ms | 352,796
FFI buffer: read 64 KiB        |   20,000 |   55.471 ms | 360,546
mapped file: write 64 KiB      |   20,000 |   55.217 ms | 362,207
mapped file: read 64 KiB       |   20,000 |   54.673 ms | 365,812
PHP string: read 64 KiB        |   20,000 |   28.850 ms | 693,252
PHP string: write 64 KiB       |      200 |  146.650 ms |   1,364
mapped file: msync 16 MiB      |      200 |    9.305 ms |  21,493
```

A mapped file and a malloc'd buffer perform identically, which they should —
both are a `memcpy` into memory this process already has mapped, and the
difference between them is ownership and lifetime, not speed. `substr()` beats
both because it is one engine-internal copy with no FFI boundary to cross.
`substr_replace()` is 250× slower than the reads because PHP has no in-place
block write into a string: every partial update copies all 16 MiB.

## Rules

1. **No benchmark here is a universal result.** Every report states its
   environment, and the text output says so in its footer.
2. **Warm up, then repeat.** One timing is not a measurement.
3. **Quote the median, show the spread.** A mean without a max hides the
   repetition that went wrong.
4. **Record both memory views**, always — half of this project's subjects are
   invisible to one of them.
5. **Keep setup out of the timed closure.** The suites allocate segments,
   sockets and mappings once and release them in a shutdown function; a
   benchmark that leaks a segment per iteration measures the leak.
6. **State the iteration count with the number.** `59.344 ms` means nothing
   without the `20,000` beside it.

## Reproduce

```bash
make benchmark ARGS="memory"                                  # PHP containers
make benchmark ARGS="ipc"                                     # socket, ring buffer, shm, semaphore
make benchmark ARGS="native"                                  # FFI buffer, mapped file, PHP string
make benchmark ARGS="native --format=csv --output=var/results/native.csv"
```
