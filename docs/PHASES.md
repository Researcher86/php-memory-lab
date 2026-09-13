# PHP Memory Lab — How It Was Built

The project is built from `PLAN.md`, a detailed thirteen-phase plan that was
folded into this file. This document is the single source of truth for the
build: what each phase was supposed to do, what exists now, what is still
open, and how the whole thing is verified.

Every completed phase is one commit to `master`. Work is only marked done
after it passes in the Docker container (`make test`, `make analyse`,
`make format-check`).

## Final Architecture

```
Experiments
(01-memory-basics … 10-ffi)
     │
     ▼
Measurements
(PHP counters  +  /proc/self/{status,smaps_rollup})
     │
     ▼
Benchmarks
(min/max/mean/median, text / JSON / CSV)
     │
     ▼
Explanations
(memory-model, php-memory-vs-rss, fork-and-cow,
 ipc-comparison, shared-memory, mmap, ffi-memory)
     │
     ▼
Reusable primitives
(MemoryReport, SocketChannel, SharedMemorySegment,
 Semaphore, RingBuffer, MappedFile, FfiBuffer)
     │
     ▼
Real backend applications
(worker pools, long-running PHP, RoadRunner, FrankenPHP,
 Swoole, queues, caches, storage engines)
```

## Core Principles

1. **Small experiments.** Break every question into the smallest experiment
   that measures it.
2. **Measure before optimizing.** No claim about memory is accepted without
   a measurement.
3. **Explain every experiment.** Each experiment states what is tested, what
   is expected, what was measured, why the result happened, and its
   limitations.
4. **Separate PHP memory from OS memory.** `memory_get_usage()` and RSS are
   different views; both are always recorded.
5. **Keep the first version simple.** Educational clarity beats production
   completeness.
6. **Preserve reproducibility.** Results record environment: PHP version,
   OS, CPU, container limits, JIT/OPcache status, allocator.
7. **Unsafety stays in disposable containers.** Double free, use-after-free,
   buffer overflow and corrupt shared-memory headers are never played with on
   a real machine.
8. **Clean up everything.** Child processes, semaphores, shared-memory
   segments, message queues, temporary files, mmap mappings and native
   allocations are always released.
9. **Platform guarantees come from the container.** The image ships the exact
   extensions the lab needs; runtime `extension_loaded()` checks are not
   used.
10. **One phase = one commit.** Phases are committed individually to `master`
    and marked done only after verification.

---

## Phase 0 — Project Setup

### Goal

A reproducible environment in which every experiment and measurement can run
identically from anywhere.

### Tasks

- [x] 0.1 Create the repository and project skeleton
- [x] 0.2 Initialize Composer
  - `researcher86/php-memory-lab`
  - `"php": "^8.5"`
  - PSR-4: `App\` → `src/`, `App\Tests\` → `tests/`
  - dev: `phpunit/phpunit ^11.0`, `phpstan/phpstan ^2.0`,
    `friendsofphp/php-cs-fixer ^3.0`
  - scripts: `test`, `analyse`, `format`, `format:check`
- [x] 0.3 Docker environment
  - `php:8.5-cli` image
  - extensions: `pcntl`, `posix`, `sockets`, `sysvmsg`, `sysvsem`,
    `sysvshm`, `ffi`
  - Xdebug (opt-in via `XDEBUG_TRIGGER=1`, host port 9003)
  - diagnostic tools: `procps`, `htop`, `strace`, `linux-perf`, `time`,
    `libffi-dev`
- [x] 0.4 Docker Compose service `php`
  - `container_name: php-memory-lab`
  - volume `.:/app`, `working_dir: /app`
- [x] 0.5 Makefile wrappers
  - `up`, `down`, `build`, `shell`, `htop`
  - `install`, `test`, `analyse`, `format`, `format-check`
  - `experiment`, `experiment-debug`, `benchmark`, `benchmark-debug`
- [x] 0.6 PHPUnit configuration (`phpunit.xml`)
- [x] 0.7 PHPStan configuration
  - level 8, paths `src`, `bin`, `tests`
- [x] 0.8 PHP-CS-Fixer configuration
  - `@PHP84Migration`, `@PSR12`, `declare_strict_types`,
    `native_function_invocation`
- [x] 0.9 `.gitignore` (vendor, caches, results, logs)
- [x] 0.10 Initial commit: `Initialize php-memory-lab project`

### Definition of Done

- `composer test` passes in the container.
- `composer analyse` passes at PHPStan level 8 with zero errors.
- `composer format:check` reports no formatting deviations.
- All three commands are repeatable via `make`.

### Tests

Phase 0 is proven by the toolchain itself: PHPUnit runs, PHPStan emits zero
errors, PHP-CS-Fixer dry-run stays clean. No runtime
`extension_loaded()` checks — the container is the guarantee.

---

## Phase 1 — Memory Measurement Basics ✅

### Goal

A reliable layer that reports both PHP-level and OS-level memory, so every
later experiment speaks the same language.

### Tasks

- [x] 1.1 `MemorySnapshot`
  - Plain data object with before/after/delta values (PHP usage, peak, RSS,
    private/PSS values)
- [x] 1.2 `/proc/self/status` reader (`ProcStatusReader`)
  - Parse `VmPeak`, `VmSize`, `VmRSS`, `RssAnon`, `RssFile`, `RssShmem`,
    `VmData`, `VmStk`, `VmExe`, `VmLib`, `VmPTE`, `VmSwap`
  - Convert `kB` values to bytes (single fixed multiplier, values differ
    only by decimal shift)
  - Support a target PID
- [x] 1.3 `/proc/self/smaps_rollup` reader (`SmapsRollupReader`)
  - `Rss`, `Pss`, `Pss_Anon`, `Pss_File`, `Pss_Shmem`, `Shared_Clean`,
    `Shared_Dirty`, `Private_Clean`, `Private_Dirty`, `Swap`
  - Returns a `SmapsRollup` value object
- [x] 1.4 Human-readable formatting (`ByteFormatter`)
  - Bytes to rounded `X.Y MB` / `X.Y KB` strings
- [x] 1.5 `MemoryReporter` + `MemoryDiff`
  - `snapshot()` composes the two readers; `diff()` computes deltas
  - null OS fields when `/proc` is unavailable or unreadable
- [x] 1.6 Basic memory experiment
  - `experiments/01-memory-basics/empty.php`
  - empty-process baseline: before allocation, after allocation, after
    cleanup (`unset`)
- [x] 1.7 Expected output format
  - `Experiment: …`, `PID: …`, `Before:`, `After:`, `Delta:` with PHP
    usage / RSS / private memory
  - context note: values depend on PHP version, allocator, container limits
- [x] 1.8 Unit tests for the reporting layer
  - `tests/Memory/` — snapshot creation, `/proc` parsing, field
    conversion, missing `/proc` files, invalid data, diff calculations
  - skip when `/proc` is unavailable (CI host is a container, so it runs)
- [x] 1.9 `docs/php-memory-vs-rss.md`
  - `memory_get_usage()`, RSS, virtual, shared, private, PSS
  - why freed PHP memory may stay in RSS, why RSS double-counts shared pages

### Definition of Done

- The empty-process experiment prints before/after/delta in the plan's
  format, reproducible via `make experiment`.
- The reporting layer is covered by unit tests.
- `docs/php-memory-vs-rss.md` explains why measurements differ.

### Tests

- `tests/Memory/ByteFormatterTest.php`
- `tests/Memory/MemorySnapshotTest.php`
- `tests/Memory/ProcStatusReaderTest.php`
- `tests/Memory/SmapsRollupReaderTest.php`
- `tests/Memory/MemoryReporterTest.php`

---

## Phase 2 — PHP Arrays, Strings and Garbage Collection ✅

### Goal

Measure what PHP data structures actually cost, and how the engine recycles
memory.

### Tasks

- [x] 2.1 String experiments
  - empty / short / 1K / 1M / 10M strings
  - `str_repeat`, concatenation, copying, `substr`
  - modification and PHP-level Copy-on-Write
  - `experiments/02-arrays-and-strings/strings.php`
- [x] 2.2 Packed array experiments
  - `range(1, 1_000_000)` vs appending in a loop
  - `experiments/02-arrays-and-strings/packed-arrays.php`
- [x] 2.3 Associative array experiments
  - string keys, sequential/sparse numeric keys
  - `experiments/02-arrays-and-strings/associative-arrays.php`
- [x] 2.4 Sparse array experiments
  - gaps in numeric keys
  - `experiments/02-arrays-and-strings/sparse-arrays.php`
- [x] 2.5 Nested arrays
  - `experiments/02-arrays-and-strings/nested-arrays.php`
- [x] 2.6 Object experiments
  - empty, scalar, string, nested properties, DTOs, arrays of objects
  - object overhead versus associative arrays
  - `experiments/02-arrays-and-strings/objects.php`
- [x] 2.7 Garbage collection
  - reference counts, cycles, `unset()`, `gc_collect_cycles()`
  - long-running worker memory growth
  - `experiments/03-garbage-collection/gc.php`
- [x] 2.8 `docs/memory-model.md`
  - zvals, refcounting, PHP-level Copy-on-Write, hash tables, packed
    arrays, arenas, allocator behavior

### Definition of Done

- Each experiment runs and prints measured before/after/delta.
- `docs/memory-model.md` explains the observed overhead.

### Tests

Unit tests for the reporting layer continue to back these experiments; the
experiments themselves are measured, printed, and interpreted in
`docs/memory-model.md`.

---

## Phase 3 — Processes and `fork()` ✅

### Goal

Understand process creation and memory inheritance between parent and child.

### Tasks

- [x] 3.1 Basic fork
  - `pcntl_fork()`, exit statuses, parent/child PIDs
  - parent RSS and child RSS measured separately
  - `experiments/04-fork/basic-fork.php`
- [x] 3.2 Fork with allocated memory
  - `range(1, 1_000_000)` before forking
  - `$reporter->diff()` in parent and child
  - `experiments/04-fork/fork-with-data.php`
- [x] 3.3 Multiple children
  - one / two / four / eight / sixteen children
  - fork time, total RSS, parent/child private memory, shared memory, PSS
  - `experiments/04-fork/fork-many.php`
- [x] 3.4 Process lifecycle
  - normal exit, non-zero exit, delayed `pcntl_waitpid()`, zombies,
    signals, child termination
  - `experiments/04-fork/process-lifecycle.php`

### Definition of Done

- Parent and child processes are measured independently.
- `docs/fork-and-cow.md` (finalized in Phase 4) documents the
  `fork()` → child exits → parent waits lifecycle.

### Tests

Integration tests cover parent/child communication and process lifecycle
(planned under `tests/Ipc/` as those primitives land). Phase 3 itself is
proven by the four recorded experiments plus `docs/fork-and-cow.md`.

---

## Phase 4 — Copy-on-Write Experiments ✅

### Goal

Show when Linux shares memory pages after `fork()` and when pages become
private — the difference between PHP-level and Linux-level CoW.

### Tasks

- [x] 4.1 Read-only child
  - child reads an inherited structure, pages stay shared; compare PSS
  - `experiments/05-copy-on-write/read-only.php`
- [x] 4.2 Modify one element
  - `$data[0] = 999;` in the child; measure RSS, private dirty memory
  - `experiments/05-copy-on-write/single-write.php`
- [x] 4.3 Modify many elements
  - one vs 1_000 vs 10_000 vs 1_000_000 writes; RSS/PSS deltas, private
    dirty memory, execution time, page faults if available
  - `experiments/05-copy-on-write/many-writes.php`
- [x] 4.4 Rewrite the entire array
  - in-place `$data[$key] = $value + 1;` vs `$data = range(...)`
  - explain why modifying and replacing behave differently
  - `experiments/05-copy-on-write/rewrite-array.php`
- [x] 4.5 Multiple children with different regions
  - four children, each touching `elements 0–249,999` … `750,000–999,999`
  - parent/child private memory, shared memory, PSS
  - `experiments/05-copy-on-write/multiple-children.php`
- [x] 4.6 `docs/fork-and-cow.md`
  - virtual address spaces, page tables, page faults, private dirty
    pages, why RSS misleads, why PSS helps, PHP CoW vs Linux page CoW

### Definition of Done

- CoW behavior is demonstrated with real, recorded measurements.
- `docs/fork-and-cow.md` explains the measurements.

### Tests

Experiments are measurable; the reporting layer already covered by Phase 1
tests. Multiline/fork tests are integration-tested where deterministic.

---

## Phase 5 — Process IPC with Unix Sockets ✅

### Goal

A baseline IPC implementation to compare against shared memory later.

### Tasks

- [x] 5.1 Unix socket pair
  - `socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets)` behind
    `SocketChannel::pair()`, so the two ends can be split across a `fork()`
  - `src/Ipc/SocketChannel.php` with `send()` / `receive()` /
    `receiveOrNull()` / `close()`
- [x] 5.2 Message framing
  - `[4-byte length][payload]`
  - handles partial reads/writes, multiple messages in one read, one
    message split across reads, empty and large payloads, invalid
    lengths, EOF, broken pipes, unexpected child termination
  - `src/Ipc/MessageFramer.php`
- [x] 5.3 IPC experiments
  - payloads `0 B` … `10 MiB`; round-trip latency, throughput, blocking
    (`receive()`) against non-blocking (`receiveOrNull()`) reads
  - `experiments/06-process-ipc/socket-latency.php`
- [x] 5.4 Serialization comparison
  - JSON, `serialize()`, CSV, a packed binary format, and a raw string as
    the floor; encode/decode time, payload size, round-trip, peak allocation
  - `experiments/06-process-ipc/serialization-compare.php`
- [x] 5.5 Backpressure experiment
  - producer faster than consumer; how much the kernel buffers absorb before
    `send()` starts waiting, how much of the producer's life is spent
    blocked, and what happens to sent messages when the producer is SIGKILLed
  - `experiments/06-process-ipc/backpressure.php`

### Definition of Done

- `SocketChannel` handles every framing edge case listed above, each covered
  by a test.
- Latency and throughput are measured per payload size; the comparison
  *against* shared memory follows in Phase 6, which reuses these numbers.

### Tests

- `tests/Ipc/MessageFramerTest.php`
  - header layout, empty payloads, the frame-size limit in both directions,
    incomplete headers
- `tests/Ipc/SocketChannelTest.php`
  - both directions, empty payload, three frames in one write, a frame
    spanning several reads, a partial frame seen by `receiveOrNull()`, a
    clean close, a close mid-frame, a write to a departed peer, I/O on a
    closed channel, and a real round trip across `pcntl_fork()`

### Notes

- The `Channel` interface was dropped: one implementation, no second one in
  sight, and the ring buffer of Phase 7 has different semantics rather than
  the same ones over another transport.
- `MessageFramer` deliberately does not reassemble frames. Only something
  holding the stream knows whether the rest of a payload has arrived, so
  reassembly lives in `SocketChannel`'s buffer and the framer stays two pure
  functions.

---

## Phase 6 — SysV Shared Memory and Semaphores ✅

### Goal

Understand shared memory between independent processes and why shared state
needs synchronization.

### Tasks

- [x] 6.1 Shared memory segment
  - `shm_attach($key, $size, 0666)` behind `SharedMemorySegment::attach()`
  - `src/Ipc/SharedMemorySegment.php` — `put()`, `get()`, `has()`,
    `remove()`, `detach()`, `destroy()`
  - documented and measured: PHP SysV shared memory serializes values — it is
    *not* a raw shared byte buffer
- [x] 6.2 Semaphores
  - `src/Ipc/Semaphore.php` — `acquire()`, `tryAcquire()`, `release()`,
    `synchronized()`, `remove()`
  - protected counter: parent creates the segment and the semaphore, forks
    children, each increments under the lock, the final value is verified
    exact, and the time each child spends *waiting* is reported separately
    from the time it spends working
  - `experiments/07-shared-memory/protected-counter.php`
- [x] 6.3 Race condition experiment
  - the same counter without synchronization, at one / two / four / eight /
    sixteen children: expected against actual, and the share of updates lost
  - `experiments/07-shared-memory/race-condition.php`
- [x] 6.4 Shared memory limitations
  - serialization overhead, segment size, synchronization requirements,
    `RssShmem` accounting, stale segments, crash consistency, and the
    comparison against the Phase 5 socket
  - `experiments/07-shared-memory/segment-lifecycle.php` and
    `docs/shared-memory.md`

### Definition of Done

- The protected counter always reaches its expected value.
- The race experiment records what is lost per child count — including the
  point where the counter stops existing at all.

### Tests

- `tests/Ipc/SemaphoreTest.php` — acquire/release cycles, `tryAcquire()`,
  `synchronized()` releasing on the failure path, use after `remove()`, and a
  two-child protected counter that must land exactly on its expected total
- `tests/Ipc/SharedMemorySegmentTest.php` — round trips for scalars, arrays
  and objects, replacing a value, reading an index that was never written, a
  value too large for the segment, `detach()` keeping the contents against
  `destroy()` taking them, and a forked child reading what the parent wrote

### Notes

- Two findings worth more than the checkboxes. Past four concurrent writers
  the unsynchronized counter does not merely lose updates — the variable
  disappears, because PHP rewrites an entry in the segment's variable
  directory by removing and re-inserting it. And PHP passes `SEM_UNDO` on
  every acquire, so the kernel releases a semaphore whose holder was killed,
  which a lock flag written into the segment by hand can never match.
- Cleanup is by key, never by object: a detached wrapper no longer holds the
  handle it would need to remove anything. The test suite leaked exactly one
  segment before this was fixed, which is the bug in miniature.

---

## Phase 7 — Shared-Memory Ring Buffer ✅

### Goal

A simple fixed-size shared-memory ring buffer. Educational, not a production
queue.

### Tasks

- [x] 7.1 Initial constraints
  - one producer, one consumer, fixed-size slots, fixed capacity, semaphore
    synchronization, polling rather than blocking
  - intentionally out of scope, and stated in the class docblock: multiple
    producers/consumers, lock-free algorithms, dynamic resizing, crash
    *recovery* (crashes are detected, never repaired), zero-copy variable-size
    messages
- [x] 7.2 Ring buffer layout
  - 32-byte header: `magic`, `version`, `capacity`, `slotSize`,
    `readPosition`, `writePosition`, `count`, `busyPid` — all 32-bit
    big-endian, so the segment means the same thing to every process
  - data area: fixed slots of `[4-byte length][payload]`
- [x] 7.3 Required operations
  - `push`, `pop`, `isEmpty`, `isFull`, `size`, `capacity`, `slotSize`,
    `busyPid`, `destroy`
  - `src/Ipc/RingBuffer.php`, on `ext-shmop` — raw bytes, because
    `shm_put_var()` would serialize each slot and keep its own variable
    directory, which is the layer this class replaces
- [x] 7.4 Experiments
  - `experiments/08-ring-buffer/throughput.php` — the same exchange through
    the locked ring, a Unix socket, and the identical layout with the
    semaphore removed
  - `experiments/08-ring-buffer/pacing.php` — full and empty behaviour,
    backpressure against a slow consumer, and the polling interval swept
    against latency and CPU
- [x] 7.5 Failure scenarios
  - `experiments/08-ring-buffer/failure-modes.php` — a key with nothing
    behind it, a segment holding other data, a segment too small for a
    header, a version this code does not speak, a message larger than a slot,
    and a producer SIGKILLed mid-update
  - handled against unsupported is explicit: the first five are refused at
    the boundary, the sixth is detected and refused, and none of them is
    repaired

### Definition of Done

- A single producer exchanges measured messages with a single consumer, in
  separate processes.
- Throughput recorded, against a socket and against the unsynchronized
  version of the same layout.

### Tests

- `tests/Ipc/RingBufferTest.php` — shape of a new buffer, FIFO order, empty
  messages, filling and refusing, popping from empty, the write position
  wrapping without disturbing order, a message larger than a slot, `create()`
  replacing an older buffer, attaching to nothing / to a foreign segment / to
  a newer version, a header left mid-update, and a producer and consumer in
  separate processes exchanging 200 messages

### Notes

- The measured result is the opposite of the intuition the phase started
  with. Shared memory is not the slow part: the unsynchronized ring is the
  fastest transport measured (~760,000 messages/s against the socket's
  ~500,000), and the same ring with one acquire and one release per message
  manages ~21,000. An acquire that finds the lock held is a sleep and a wake,
  tens of microseconds against the ~8 µs the operation itself costs.
- Two leaks were found by checking `/proc/sysvipc` after every run rather
  than by any test failing. `attach()` used to create the semaphore before
  validating the header, so every attach to a foreign segment left one
  behind; and `create()` now removes the old semaphore along with the old
  segment, so a new buffer never inherits the lock state of whatever ran
  there before.
- `ext-shmop` was added to the image, `composer.json` and CI for this phase.

---

## Phase 8 — mmap ✅

### Goal

Understand memory-mapped files and their relationship to virtual memory.

### Tasks

- [x] 8.1 Basic concepts
  - file-backed mappings, `MAP_SHARED` against `MAP_PRIVATE`, page faults,
    lazy loading, dirty pages, persistence, mapping size, truncation,
    `msync()`, unmapping — each one measured rather than asserted
- [x] 8.2 Implementation options
  - FFI → libc, as the plan chose. PHP has no `mmap()` and no way to reach
    the descriptor behind a stream, so the file is opened through libc as well
  - `src/Native/Libc.php` holds every dynamic FFI call in the project, wrapped
    in typed methods, so the rest of the lab stays analysable
- [x] 8.3 `MappedFile` API
  - `open($path, $size, $shared)`, `read($offset, $length)`,
    `write($offset, $data)`, `flush()`, `unmap()`, `address()`, `isMapped()`
  - `src/Native/MappedFile.php`
- [x] 8.4 Experiments
  - `experiments/09-mmap/lazy-loading.php` — 256 MiB mapped under a 128 MiB
    `memory_limit`, RSS and page faults as pages are touched, against
    `file_get_contents()` of the same file
  - `experiments/09-mmap/shared-vs-private.php` — visibility in both modes
    across two processes, RSS/PSS/Shared_Dirty accounting, `msync()` cost
    dirty and clean
- [x] 8.5 Failure scenarios
  - `experiments/09-mmap/failure-modes.php` — read and write past the end,
    negative offsets, a zero-byte mapping, unmapping twice, using an unmapped
    file, and a file truncated while mapped
  - the last one is SIGBUS and runs inside a forked child that is expected to
    die, per the project's rule about unsafe experiments

### Definition of Done

- `MappedFile` maps, reads, writes, flushes and unmaps real files.
- Failure scenarios are observed and contained.

### Tests

- `tests/Native/MappedFileTest.php` — round trips, a fresh mapping reading as
  zeroes, page alignment, the file being grown to the mapping, a shared write
  reaching the file, a private write not reaching it, bounds in both
  directions, negative offsets, the last byte being reachable, unmapping
  twice, use after unmap, a zero-byte mapping, and two processes sharing one
  mapping of the same file

### Notes

- The headline measurement: mapping 256 MiB costs 220 KiB of RSS and zero page
  faults, and succeeds under a 128 MiB `memory_limit` — the limit counts what
  the PHP allocator hands out, and a mapping is not that.
- Faults come in far below one per page: fifteen faults for four thousand
  pages touched, about a megabyte each, because the kernel reads ahead and
  maps whole folios.
- `MAP_PRIVATE` and `fork()` turn out to be the same mechanism seen from two
  directions, and they account identically — `Shared_Dirty` becoming
  `Private_Dirty` when the other sharer leaves, without a byte moving.
- PHPStan gets one narrow `ignoreErrors` entry, for `method.notFound` in
  `Libc.php` only: FFI resolves C functions at runtime, so every libc call
  looks like a call to an undefined method. Confining them to one file is
  what keeps the rule that narrow.

---

## Phase 9 — FFI and Native Memory ✅

### Goal

Understand memory allocated outside the PHP engine.

### Tasks

- [x] 9.1 Native allocation
  - `malloc(size_t)` / `free(void*)` through the same `Libc` handle Phase 8
    introduced
- [x] 9.2 `FfiBuffer`
  - `allocate($size)`, `write($offset, $data)`, `read($offset, $length)`,
    `free()`, `address()`, `isAllocated()`, plus a destructor as a backstop
  - strict boundary validation: negative offsets and lengths, offsets at or
    past the end, and lengths that would overflow an `$offset + $length`
    comparison
  - `src/Native/FfiBuffer.php`
- [x] 9.3 Memory ownership experiments
  - `experiments/10-ffi/native-allocation.php` — 256 MiB allocated under a
    128 MiB `memory_limit`, RSS moving while the PHP counters do not, and
    64 MiB leaked by dropping a pointer
  - `experiments/10-ffi/unsafe-ownership.php` — double free, write after
    free, read after free, and a 4 KiB write into a 64-byte allocation, each
    in its own forked child, with the same three mistakes shown being refused
    by `FfiBuffer`
- [x] 9.4 Compare PHP and native buffers
  - `experiments/10-ffi/buffer-comparison.php` — the same 16 MiB as a PHP
    string, a PHP array, an FFI buffer and a mapped file: allocation, write,
    read and release timings against PHP usage and RSS, held and after release
- [x] 9.5 `docs/ffi-memory.md`
  - pointers, ownership, allocation and deallocation, boundaries, lifetime,
    undefined behaviour, ABI as an unchecked contract, and why RSS is the only
    counter that describes any of it

### Definition of Done

- `FfiBuffer` validates every access and never lets a read or write escape its
  bounds.
- The native-against-PHP comparison is recorded.

### Tests

- `tests/Native/FfiBufferTest.php` — round trips, the whole buffer and its
  last byte being addressable, reads and writes past the end, an offset at the
  end, negative offsets and lengths, a `PHP_INT_MAX` offset that would
  overflow a naive bounds check, zero and negative allocations, freeing twice,
  use after free, allocations not overlapping, and 32 MiB of native memory
  moving `memory_get_usage()` by less than a kilobyte
- The unsafe ownership experiments are deliberately not in the automated
  suite: several of them end the process on purpose

### Notes

- The most useful result is how little the severity of a mistake has to do
  with whether anything reports it. A double free is caught instantly and by
  name; a 4 KiB write into a 64-byte allocation was not reported by the write,
  by the `free()` of the corrupted chunk, or by sixty-four allocations
  afterwards.
- `ext-ffi` was added to `composer.json`. It had been in the image and in CI
  since Phase 0 but was never declared, which only became wrong once Phases 8
  and 9 made it load-bearing.
- The bounds check is written as `$offset > $size || $length > $size - $offset`
  rather than `$offset + $length > $size`, because the latter overflows for a
  large offset and wraps negative — turning the guard into a guarantee of the
  access it exists to stop. There is a test that passes `PHP_INT_MAX`.

---

## Phase 10 — Benchmark Harness ✅

### Goal

A reusable benchmark system for all experiments — same input shape, same
output shape.

### Tasks

- [x] 10.1 `BenchmarkRunner`
  - `run($name, $operation, $iterations = 1): BenchmarkResult`, with
    `repetitions` and `warmups` configured per runner
  - `src/Benchmark/BenchmarkRunner.php`
- [x] 10.2 `BenchmarkResult`
  - readonly: `name`, `iterations`, `repetitions`, `elapsedSeconds`,
    `timings`, `phpDelta`, `rssDelta`, plus `operationsPerSecond()` and
    `secondsPerOperation()` derived from the median
- [x] 10.3 Output formats
  - `src/Benchmark/Formatter/` — `TextFormatter`, `JsonFormatter`,
    `CsvFormatter` behind one `Formatter` interface
- [x] 10.4 Benchmark rules
  - `Environment::detect()` records PHP version, OS, kernel, CPU and core
    count, `memory_limit`, the cgroup memory limit, OPcache, JIT and the
    allocator, and every report carries it
  - `docs/BENCHMARKS.md` states the rules, including that no result here is
    universal — which the text output repeats in its own footer
- [x] 10.5 Benchmark repetitions
  - warm-up repetitions run and are discarded, timed repetitions produce a
    `Timings` distribution (min/max/mean/median/p95), and `ops/sec` comes from
    the median
  - `benchmarks/memory.php`, `benchmarks/ipc.php`, `benchmarks/native.php`;
    results to `var/results/` via `--output`

### Definition of Done

- Any experiment can be benchmarked with one consistent call.
- Output is human-readable and machine-readable (JSON/CSV).

### Tests

- `tests/Benchmark/BenchmarkRunnerTest.php` — iterations per repetition,
  warm-ups running but not being timed, the result's shape, `ops/sec` coming
  from the median, the memory delta covering the whole timed run, and zero
  iterations being refused
- `tests/Benchmark/TimingsTest.php` — a single sample, unsorted input, the
  mean following an outlier where the median does not, nearest-rank median on
  an even count, and the 95th percentile of twenty samples
- `tests/Benchmark/FormatterTest.php` — the environment appearing in all three
  formats, valid JSON with both memory views, a null RSS delta staying null
  rather than becoming zero, and one CSV header plus one row per result

### Notes

- `bin/benchmark` is a real CLI now: suites discovered from `benchmarks/`,
  `--format`, `--output`, `--iterations`, `--repetitions`, `--warmups`.
  Progress goes to stderr so that `--format=json > file` stays valid JSON.
- The iteration count belongs to the `Benchmark` rather than to the run,
  because it is a property of the operation. A single global default would
  make either the socket round trip or the million-element allocation
  meaningless.
- Suites create their sockets, segments and mappings once and release them in
  a shutdown function. A benchmark that leaks a segment per iteration measures
  the leak.

---

## Phase 11 — Experiments CLI ✅

### Goal

One consistent way to run any experiment.

### Tasks

- [x] 11.1 `bin/experiment` commands
  - every experiment in `experiments/` is reachable by name, and the list in
    `--help` is the directory rather than a copy of it that drifts
  - `memory:*`, `process:*`, `cow:*`, `ipc:*`, `shm:*`, `ring:*`, `mmap:*`,
    `ffi:*` — thirty-two of them
- [x] 11.2 Common options
  - `--size`, `--elements`, `--children`, `--iterations`, `--payload-size`,
    `--duration`, `--sleep`, plus `--format=text|json` and `--output=PATH`
  - each is validated as a positive integer, and an experiment that does not
    read an option refuses it rather than ignoring it
- [x] 11.3 Experiment structure
  - `src/Experiment/Experiment.php` — name, description, the options it
    honours, and the closure that runs it
  - `src/Experiment/Options.php`, `Output.php`, `Registry.php`,
    `ExperimentRunner.php`, `ExperimentResult.php`
  - every experiment returns a descriptor and does its allocating inside the
    closure, so building the registry has no side effects

### Definition of Done

- `php bin/experiment <name>` runs every experiment with the common options.
- Output switches between prose and JSON with `--format`.

### Tests

- `tests/Experiment/OptionsTest.php` — defaults, overrides, unknown names,
  zero and negative values, and reporting only what was passed
- `tests/Experiment/OutputTest.php` — prose reaching the stream, a quiet
  output still collecting measurements, a missing OS field reading as `n/a`,
  signed deltas
- `tests/Experiment/RegistryTest.php` — **the whole `experiments/` directory
  loading**, every name unique and well-formed, every declared option being
  one the CLI knows, plus fixtures for a file that returns the wrong thing and
  two experiments sharing a name
- `tests/Experiment/ExperimentRunnerTest.php` — options reaching the
  experiment, an unsupported option being refused, defaults not being reported
  as if they were passed, and the JSON shape

### Notes

- `experiments/run-helpers.php` is gone. Its global functions are methods on
  `Output` now, which is the difference between a forked child writing through
  an instance it inherited — visible in the code — and one writing through a
  global.
- `Output` does two jobs: prose streams out as it is produced, so an
  experiment that forks and then waits does not look hung, while
  `measure()` collects the numbers that `--format=json` reports. In JSON mode
  the prose is suppressed rather than captured, because several experiments
  write from more than one process at once and interleaved output inside a
  JSON string would be neither readable nor valid.
- The registry discovers experiments instead of listing them. The list had
  been hard-coded in `bin/experiment` since Phase 1 and drifted from the
  directory twice; `RegistryTest` now loads all thirty-two on every test run,
  so a broken descriptor fails in the suite rather than the first time someone
  runs that experiment.

---

## Phase 12 — Documentation

### Goal

Turn raw experiment output into explanations that answer *what*, *why*, and
*how it can be proven*.

### Tasks

- [ ] `docs/memory-model.md`
  - zvals, refcounting, PHP CoW, hash tables, packed/associative arrays,
    arenas, allocator behavior, GC, object/string overhead
- [ ] `docs/php-memory-vs-rss.md`
  - `memory_get_usage()`, peak, RSS, virtual, shared, private, PSS, why
    measurements differ, freed memory in RSS, RSS double-counting
- [ ] `docs/fork-and-cow.md`
  - `fork()`, address spaces, page tables, shared pages, page faults,
    private dirty pages, accounting, PHP CoW vs Linux CoW
- [ ] `docs/ipc-comparison.md`
  - Unix socket, SysV queue, SysV shared memory, semaphore, `mmap()`, FFI
    across purpose / copying / synchronization / complexity
- [ ] `docs/shared-memory.md`
  - addressability, synchronization, visibility, races, atomicity,
    cleanup, crash consistency, segment lifecycle, stale resources
- [ ] `docs/mmap.md`
  - file-backed/anonymous mappings, `MAP_SHARED`/`MAP_PRIVATE`, `msync()`,
    page faults, persistence, lifetime, truncation
- [ ] `docs/ffi-memory.md`
  - pointers, ownership, allocation, deallocation, boundaries, lifetime,
    undefined behavior, ABI, accounting

### Definition of Done

- Every experiment has a written explanation tied to its measurements.
- README links all documents with reproducible commands.

### Tests

Documentation phases are validated by review against the measurements they
describe; the automated suite itself stays green.

---

## Recommended Implementation Order

The plan was staged in milestones; each maps onto the phases above and kept
the repository walkable at every commit.

1. **Memory Reporter** — Phase 1 → `src/Memory/`,
   `experiments/01-memory-basics/`, `tests/Memory/`,
   `docs/php-memory-vs-rss.md`
2. **Arrays, Strings and GC** — Phase 2 →
   `experiments/02-arrays-and-strings/`, `experiments/03-garbage-collection/`,
   `docs/memory-model.md`
3. **Fork and CoW** — Phases 3–4 →
   `experiments/04-fork/`, `experiments/05-copy-on-write/`,
   `docs/fork-and-cow.md`
4. **Socket IPC** — Phase 5 → `src/Ipc/SocketChannel.php`,
   `src/Ipc/MessageFramer.php`, `experiments/06-process-ipc/`,
   `benchmarks/ipc.php`
5. **SysV IPC** — Phase 6 → `src/Ipc/SharedMemorySegment.php`,
   `src/Ipc/Semaphore.php`, `experiments/07-shared-memory/`,
   `docs/shared-memory.md`
6. **Ring Buffer** — Phase 7 → `experiments/08-ring-buffer/`,
   `src/Ipc/RingBuffer.php`
7. **mmap** — Phase 8 → `experiments/09-mmap/`,
   `src/Native/MappedFile.php`, `docs/mmap.md`
8. **FFI** — Phase 9 → `experiments/10-ffi/`,
   `src/Native/FfiBuffer.php`, `docs/ffi-memory.md`
9. **Benchmark Framework** — Phase 10 → `src/Benchmark/`, `benchmarks/`,
   `var/results/`

## First MVP

The first version stays intentionally small:

- Composer project + Docker environment (Phase 0)
- `MemorySnapshot`, `MemoryReporter`, `/proc/self/status` parser (Phase 1)
- Basic allocation experiments (Phase 1)
- Array/string experiments (Phase 2)
- Basic `fork()` and CoW experiments (Phases 3–4)
- JSON results, PHPUnit tests, README with explanations

Suggested first tasks:

1. Create the repository
2. Add `composer.json`
3. Add the Docker environment
4. Add PHPUnit
5. Implement `MemorySnapshot`
6. Implement `ProcStatusReader`
7. Implement `MemoryReporter`
8. Add the first memory experiment
9. Add JSON output
10. Add tests
11. Document PHP memory versus RSS
12. Implement the first `fork()` experiment
13. Implement the first CoW experiment
14. Add benchmark measurements
15. Publish the first MVP

## Project-wide Definition of Done

The project is ready for the next stage when:

- All MVP experiments run inside Docker.
- Memory measurements are available in human-readable and JSON formats.
- `/proc/self/status` is parsed correctly.
- Parent and child processes can be measured independently.
- Copy-on-Write behavior is demonstrated with real measurements.
- Tests cover the memory-reporting layer.
- Each experiment explains what is tested, what is expected, what was
  measured, why the result happened, and its limitations.
- README contains reproducible commands.
- Results include environment information.
- No benchmark is presented as a universal result.
- Unsafe experiments are isolated.
- IPC resources are cleaned up correctly.

## Testing Strategy

### Unit tests

- snapshot creation, `/proc` parsing and field conversion
- missing `/proc` files and invalid data
- memory diff calculations
- message framing: partial reads/writes, invalid lengths, empty payloads
- ring buffer state transitions, semaphore lifecycle
- native buffer bounds

### Integration tests

- parent/child process communication
- Unix socket pair
- SysV shared memory and semaphores
- ring buffer producer/consumer
- FFI allocation/cleanup, mmap read/write behavior

### Platform tests

The Docker image ships exact extensions (`pcntl`, `posix`, `sockets`,
`sysvmsg`, `sysvsem`, `sysvshm`, `ffi`) and a real `/proc`, so the CI host
and the developer container match. Tests that read `/proc` are skipped when
it is unavailable (e.g. on a non-Linux host, which this project does not
target). Runtime `extension_loaded()` checks are deliberately avoided.

```php
if (!is_readable('/proc/self/status')) {
    self::markTestSkipped('/proc is unavailable');
}
```

## Benchmarking Strategy

### Always record the environment

PHP version, OS, CPU architecture/model, container limits, memory limit,
JIT status, OPcache status, allocator, kernel version.

### Benchmark categories

- **Memory allocation** — strings, arrays, objects, nested structures,
  native buffers, mmap regions
- **Process creation** — one fork, multiple forks, with/without large
  pre-allocated memory, fork after warm-up
- **Copy-on-Write** — read-only access, one write, many writes, full
  rewrite, multiple children
- **IPC** — Unix socket, SysV message queue, SysV shared memory, ring
  buffer, varying payload sizes
- **Native memory** — PHP string, FFI buffer, mmap buffer, allocation,
  copying, reading, writing

## Safety Rules

- **Run unsafe experiments in containers.** Double free, use-after-free,
  buffer overflow, invalid pointer access, invalid mappings, corrupted
  shared-memory headers — disposable containers only.
- **Never run unsafe experiments on production.** Not on production
  servers, not against production databases, not in shared development
  environments, not with sensitive data, not without resource limits.
- **Always validate buffer boundaries.** Validate offsets and lengths,
  check integer overflow and allocation size, reject negatives and
  larger-than-buffer values, release resources explicitly.
- **Always clean up IPC resources.** Child processes, semaphores,
  shared-memory segments, message queues, temporary files, mmap mappings,
  native allocations.

## Future Extensions

- `/proc/[pid]/maps` and `/proc/[pid]/smaps` parsers
- page-fault measurements, `perf stat`, `strace` wrappers
- signal and process-supervisor experiments
- shared-memory object pools and hash tables
- lock-free structures, atomic counters, futex via FFI
- POSIX shared memory and semaphores, `eventfd`, `memfd_create`
- huge pages, THP, NUMA, CPU affinity
- memory pressure, OOM-killer, cgroup memory limits
- PHP worker-pool integration; RoadRunner, FrankenPHP and Swoole
  worker-memory experiments
- PHP-to-C and PHP-to-Go integration through FFI; custom PHP extensions

## Final Project Direction

The long-term goal is to understand how PHP, Linux processes, memory
management, IPC and native code interact in real backend systems. The result
is not a collection of scripts:

```
Experiments → Measurements → Benchmarks → Explanations
    → Reusable primitives → Real backend applications
```

The project should answer not only *how do I implement this?*, but also
*what is happening inside PHP and Linux, why does it happen, and how can I
prove it with measurements?*