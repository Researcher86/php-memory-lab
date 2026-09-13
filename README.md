# PHP Memory Lab

> A hands-on laboratory for PHP memory, Linux processes, Copy-on-Write, IPC, shared memory, `mmap`, and native allocations - one small, measured, explained experiment at a time.

A **PHP runtime engineering playground**: every topic is turned into the
smallest experiment that proves it, the result is measured at both levels
that matter, and the numbers are explained in the docs instead of asserted.

The goal is not to build a production memory manager or a shared-memory
framework. It is to have a place where you can watch what actually happens
underneath a PHP process - how much an array costs, when a forked child stops
sharing pages, how shared memory differs from raw bytes, why
`memory_get_usage()` and RSS disagree - and to be able to prove each answer
with a measurement instead of trusting a textbook.

Anything unsafe (double free, use-after-free, corrupt shared-memory headers)
stays in disposable containers.

```text
Experiments
   │
   ▼
Measurements    PHP counters  +  /proc/self/{status,smaps_rollup}
   │
   ▼
Benchmarks      min/max/mean/median, text / JSON / CSV
   │
   ▼
Explanations    docs/memory-model.md ... docs/ffi-memory.md
   │
   ▼
Reusable primitives → real backend applications
```

---

## 30-second demo

Requires Docker. Nothing is installed on your machine.

```bash
make install          # build the image and install dependencies
make test             # every primitive in src/, verified
```

The container is a real Linux with a real `/proc`, which is the whole point.
Open a shell and look at what PHP and the OS each think a process holds:

```bash
make shell
php -r 'echo memory_get_usage(true), " ", file_get_contents("/proc/self/status");' | grep -E 'VmRSS|RssAnon|^[0-9]+ '
```

`vmRSS` is the OS view (resident pages), `memory_get_usage()` is the PHP view
(engine-managed bytes). They do not agree, and the project exists to make
that gap measurable.

Then run any of the thirty-two experiments, or ask for the list:

```bash
make experiment                                   # every experiment, with its description
make experiment ARGS="memory:empty"               # the smallest one
make experiment ARGS="cow:many-writes --children=8"
make experiment ARGS="ring:throughput"            # the one whose result is most surprising
make benchmark  ARGS="ipc"                        # the same mechanisms, timed and repeated
```

See [Docs](#docs) for what each phase produces and how it is verified.

---

## What it does

| | |
|---|---|
| **Memory reporter** | one snapshot that joins PHP counters and `/proc` metrics, plus diffs between two snapshots |
| **Two memory levels** | `memory_get_usage()` and RSS/PSS are both recorded, never one alone |
| **`/proc` readers** | `status` and `smaps_rollup` parsed at the byte level, `kB` → bytes, against a target PID |
| **Swappable platform** | reader implementations swap between the container and fixture files for tests |
| **Experiments** | every mechanism has a runnable experiment that prints before/after/delta |
| **Fork & CoW** | parent and child measured independently; shared vs private pages quantified with PSS |
| **IPC** | Unix sockets, SysV shared memory, semaphores, then a shared-memory ring buffer |
| **Native memory** | `mmap` through FFI, bounded native buffers, PHP-vs-native comparison |
| **Benchmarks** | environment-recorded, repeated, min/max/mean/median, text / JSON / CSV |
| **CLI** | one interface for every experiment via `bin/experiment` |

---

## The mechanisms, and where to read each one

Every file below is standalone enough to open cold.

| To understand how… | Open |
|---|---|
| PHP and OS memory disagree, and why that matters | [`src/Memory/MemorySnapshot.php`](src/Memory/MemorySnapshot.php) · [`docs/PHASES.md`](docs/PHASES.md) (Phase 1) |
| `/proc/self/status` fields map to virtual/RSS/private memory | [`src/Memory/ProcStatusReader.php`](src/Memory/ProcStatusReader.php) |
| `smaps_rollup` splits memory into PSS, shared and private-dirty | [`src/Memory/SmapsRollupReader.php`](src/Memory/SmapsRollupReader.php) · [`src/Memory/SmapsRollup.php`](src/Memory/SmapsRollup.php) |
| raw bytes become readable human sizes | [`src/Memory/ByteFormatter.php`](src/Memory/ByteFormatter.php) |
| arrays, strings, objects and GC actually cost memory | `experiments/02-arrays-and-strings/` · `experiments/03-garbage-collection/` |
| `fork()` shares and later splits pages (CoW) | `experiments/04-fork/` · `experiments/05-copy-on-write/` |
| an experiment declares its name, options and measurements | [`src/Experiment/Experiment.php`](src/Experiment/Experiment.php) · [`src/Experiment/Registry.php`](src/Experiment/Registry.php) |
| a benchmark records the machine it ran on | [`src/Benchmark/BenchmarkRunner.php`](src/Benchmark/BenchmarkRunner.php) · [`src/Benchmark/Environment.php`](src/Benchmark/Environment.php) |
| messages stay framed over a byte stream | [`src/Ipc/SocketChannel.php`](src/Ipc/SocketChannel.php) · [`src/Ipc/MessageFramer.php`](src/Ipc/MessageFramer.php) |
| SysV shared memory differs from raw bytes, and why it races | [`src/Ipc/SharedMemorySegment.php`](src/Ipc/SharedMemorySegment.php) · [`src/Ipc/Semaphore.php`](src/Ipc/Semaphore.php) |
| a fixed-size shared ring buffer synchronizes one producer/consumer | [`src/Ipc/RingBuffer.php`](src/Ipc/RingBuffer.php) |
| files map into the address space | [`src/Native/MappedFile.php`](src/Native/MappedFile.php) · [`src/Native/Libc.php`](src/Native/Libc.php) |
| native memory lives outside the engine, and what that unlocks | [`src/Native/FfiBuffer.php`](src/Native/FfiBuffer.php) |

All thirteen phases are built; the roadmap below is the record of how. Every
file linked here is standalone enough to open cold.

---

## Docs

| | |
|---|---|
| **[docs/PHASES.md](docs/PHASES.md)** | how it was built - the full plan, phase by phase, each with Goal / Tasks / Definition of Done / Tests |
| **[docs/DECISIONS.md](docs/DECISIONS.md)** | what was decided and why: PHP 8.5, `App\` namespace, no `extension_loaded()` checks, plan folded |
| **[docs/BENCHMARKS.md](docs/BENCHMARKS.md)** (Phase 10) | how the harness runs, how to read a report, and why no result here is universal |
| **[docs/memory-model.md](docs/memory-model.md)** (Phase 2) | zvals, refcounting, hash tables, packed arrays, arenas, allocator, GC |
| **[docs/php-memory-vs-rss.md](docs/php-memory-vs-rss.md)** (Phase 1) | why the two measurements differ, when freed memory stays resident, PSS |
| **[docs/fork-and-cow.md](docs/fork-and-cow.md)** (Phase 4) | virtual address spaces, page tables, shared pages, private dirty pages |
| **[docs/ipc-comparison.md](docs/ipc-comparison.md)** (Phase 12) | Unix socket vs SysV queue vs shared memory vs `mmap` vs FFI: what each copies, synchronizes and leaks |
| **[docs/shared-memory.md](docs/shared-memory.md)** (Phase 6) | races, atomicity, cleanup, crash consistency, segment lifecycle |
| **[docs/mmap.md](docs/mmap.md)** (Phase 8) · **[docs/ffi-memory.md](docs/ffi-memory.md)** (Phase 9) | mappings and native ownership, with their hazards |
| the rest of this file | the concepts, in depth |

---

## Development

```bash
make test            # PHPUnit
make analyse         # PHPStan level 8
make format-check    # PHP-CS-Fixer dry run
make format          # apply PHP-CS-Fixer
make shell           # a shell in the container (real /proc)
make htop            # watch the container's processes
```

Debugging is opt-in (`Xdebug` is installed in `trigger` mode), so an
experiment that forks a parent plus N children does not make all of them
reach for a debugger that is not there:

```bash
make experiment-debug ARGS="cow:many-writes --children=4"
```

Point the IDE at port 9003 first; without a listener the connection attempt
just times out and the run continues.

---

# Architecture

```text
                    EXPERIMENTS                       BENCHMARKS
    01-memory-basics   06-process-ipc       repeatable, env-recorded
    02-arrays-strings  07-shared-memory     min / max / mean / median
    03-garbage-coll.   08-ring-buffer       text / JSON / CSV
    04-fork            09-mmap
    05-copy-on-write   10-ffi
                         │                                 │
                         └────────────────┬────────────────┘
                                          ▼
                             ┌────────────────────────┐
                             │     MemoryReporter     │
                             └────────────────────────┘
                                          │
               ┌──────────────────────────┼──────────────────────────┐
               ▼                          ▼                          ▼
    ┌────────────────────┐     ┌────────────────────┐     ┌────────────────────┐
    │ /proc/self/status  │     │    smaps_rollup    │     │    PHP counters    │
    │  VmRSS, RssAnon,   │     │ Pss, Shared_Dirty, │     │ memory_get_usage() │
    │  VmSize, RssShmem  │     │   Private_Dirty    │     │  peak, real usage  │
    └────────────────────┘     └────────────────────┘     └────────────────────┘
               │                          │                          │
               └──────────────────────────┼──────────────────────────┘
                                          ▼
                             ┌────────────────────────┐
                             │      Explanations      │   docs/*.md
                             └────────────────────────┘
                                          │
                                          ▼
          Reusable primitives  →  real backend applications
```

---

# How It Works

The whole project rests on one distinction that most PHP code never has to
make: **the PHP engine's view of memory is not the operating system's view**.

```text
memory_get_usage()          RSS (VmRSS)
  engine-managed bytes       resident physical pages
  grows/shrinks with code    grows/shrinks but rarely back
  knows about zvals          knows nothing about zvals
```

A process can show stable PHP memory while its RSS climbs (native buffers,
shared-memory segments, `mmap`, allocator retention), and the reverse - PHP
releases an object while the pages stay mapped as anonymous memory.

So the lab measures both, always:

```text
Before:
  PHP usage:   2.1 MB
  RSS:        18.4 MB
  Private:    12.7 MB

After:
  PHP usage:  34.2 MB
  RSS:        51.8 MB
  Private:    46.1 MB

Delta:
  PHP usage:  +32.1 MB
  RSS:       +33.4 MB
```

## Why RSS is not the same as "used"

`/proc/self/status` gives the per-metric breakdown:

| Field | Meaning |
|---|---|
| `VmPeak` / `VmSize` | virtual address space (peak / current) - can be enormous without touching RAM |
| `VmRSS` | resident pages, shared or not |
| `RssAnon` / `RssFile` / `RssShmem` | what the resident pages back |
| `VmData` | the heap region |
| `VmPTE` / `VmLib` / `VmStack` | page tables, libraries, stack |

`smaps_rollup` goes further and tells you *who* owns each resident page:

| Field | Meaning |
|---|---|
| `Pss` | proportional size - shared pages divided across sharers, the honest number for per-process accounting |
| `Private_Clean` / `Private_Dirty` | pages only this process owns |
| `Shared_Clean` / `Shared_Dirty` | pages shared with other processes (e.g. after `fork()`) |

Two processes each show the full shared page in RSS; only PSS shows each as
half. That is the whole Copy-on-Write story in one row.

## `fork()` and Copy-on-Write

`fork()` gives the child a copy of the parent's *page tables*, not its
pages. Both processes point at the same physical pages until one writes -
the kernel copies the faulted page and the writer keeps its private copy.
Reads never cost anything; writes are lazy and page-grained.

```text
          before fork          after fork             after the child writes

          parent              parent         child          parent       child
        ┌────────┐          ┌────────┐    ┌────────┐      ┌────────┐  ┌────────┐
        │ vaddr  │          │ vaddr  │    │ vaddr  │      │ vaddr  │  │ vaddr  │
        └────┬───┘          └────┬───┘    └────┬───┘      └────┬───┘  └────┬───┘
             │                   └──────┬──────┘               │           │
             ▼                          ▼                      ▼           ▼
        ╔════════╗                 ╔════════╗             ╔════════╗  ╔════════╗
        ║  page  ║                 ║  page  ║             ║  page  ║  ║ page'  ║
        ╚════════╝                 ╚════════╝             ╚════════╝  ╚════════╝

    one mapping,          one page, two mappings:     the write faulted; the
    one page              RSS counts it in both,      writer's page is private
                          PSS counts it half each     now - Private_Dirty +4 KiB
```

The experiments measure the transition with RSS, PSS and private-dirty
instead of assuming either process owns all the pages.

## Messages over streams need framing

A socket is a byte stream; one `read()` is not one message. The IPC layer
therefore frames everything:

```text
┌──────────────┬────────────────────┐
│ payload size │ payload            │
│    4 bytes   │ N bytes            │
└──────────────┴────────────────────┘
```

and must survive partial reads, several messages in one read, broken pipes,
and a child that dies mid-frame.

---

# Roadmap

## Phase 0 — Project Setup

* [x] Composer project (`researcher86/php-memory-lab`, PHP 8.5, PSR-4 `App\` → `src/`, platform extensions declared)
* [x] Dockerfile: `php:8.5-cli` + `pcntl`, `posix`, `shmop`, `sockets`, `sysvsem`, `sysvshm`, `ffi`
* [x] Docker Compose, Makefile (test/analyse/format/shell/htop), PHPUnit, PHPStan level 8, PHP-CS-Fixer
* [x] Initial commit: `Initialize php-memory-lab project`

## Phase 1 — Memory Measurement Basics

* [x] `MemorySnapshot`, `ByteFormatter`
* [x] `/proc/self/status` reader (`ProcStatusReader`)
* [x] `/proc/self/smaps_rollup` reader (`SmapsRollupReader`, `SmapsRollup`)
* [x] `MemoryReporter` (snapshot/diff)
* [x] `experiments/01-memory-basics/empty.php`
* [x] unit tests for the reporting layer (`tests/`)
* [x] `docs/php-memory-vs-rss.md`

## Phase 2 — PHP Arrays, Strings and Garbage Collection

* [x] string experiments (empty … 10M, concatenation, copies, CoW)
* [x] packed / associative / sparse / nested array experiments
* [x] object experiments
* [x] GC: reference counts, cycles, `unset()`, `gc_collect_cycles()`
* [x] `docs/memory-model.md`

## Phase 3 — Processes and `fork()`

* [x] basic fork, fork with a 1M-element array
* [x] one / two / four / eight / sixteen children (RSS, shared, PSS)
* [x] process lifecycle: zombies, `waitpid`, signals, exit codes

## Phase 4 — Copy-on-Write Experiments

* [x] read-only child, single write, many writes, full rewrite
* [x] four children writing different regions
* [x] `docs/fork-and-cow.md` (finalized)

## Phase 5 — Process IPC with Unix Sockets

* [x] `SocketChannel` over `socket_create_pair()`, blocking and polling reads
* [x] length-prefixed framing (partial reads/writes, EOF, broken pipes)
* [x] latency/throughput experiments (`0 B` … `10 MiB`), serialization comparison, backpressure

## Phase 6 — SysV Shared Memory and Semaphores

* [x] `SharedMemorySegment` (serialized values ≠ raw bytes, lifecycle, `RssShmem`)
* [x] `Semaphore` with `synchronized()`, and a protected counter that is always exact
* [x] race-condition experiment (lost updates, then a destroyed counter, at 1…16 children)
* [x] `docs/shared-memory.md`

## Phase 7 — Shared-Memory Ring Buffer

* [x] header + fixed-size slots, semaphore sync, one producer / one consumer
* [x] full/empty behavior, throughput against a socket, speed mismatch, polling dial
* [x] failure scenarios measured (bad magic/version, oversized message, a writer killed mid-update)

## Phase 8 — mmap

* [x] `MappedFile`: `open`/`read`/`write`/`flush`/`unmap` (FFI → libc)
* [x] lazy loading and page faults, `MAP_SHARED` vs `MAP_PRIVATE` across two processes, `msync`
* [x] failure scenarios including SIGBUS on truncation, contained in a child
* [x] `docs/mmap.md`

## Phase 9 — FFI and Native Memory

* [x] `FfiBuffer` with strict boundary validation (including the overflow case)
* [x] ownership experiments (contained): double free, use-after-free, overflow, leaks
* [x] PHP string vs array vs FFI buffer vs mapped file, `docs/ffi-memory.md`

## Phase 10 — Benchmark Harness

* [x] `BenchmarkRunner` / `BenchmarkResult` / `Timings` (readonly)
* [x] text / JSON / CSV output; warm-up, repetitions, min/max/mean/median/p95
* [x] every report records its environment; `benchmarks/memory|ipc|native`

## Phase 11 — Experiments CLI

* [x] `bin/experiment memory:* process:* cow:* ipc:* shm:* ring:* mmap:* ffi:*` — discovered, not listed
* [x] common options (`--elements`, `--children`, `--size`, `--iterations`, …), validated and refused when unused
* [x] `--format=text|json`, `--output=PATH`

## Phase 12 — Documentation

* [x] `memory-model.md`, `php-memory-vs-rss.md`, `fork-and-cow.md`,
      `ipc-comparison.md`, `shared-memory.md`, `mmap.md`, `ffi-memory.md`
* [x] every experiment explained against its own measurements; every link, path
      and command checked by `tests/DocumentationTest.php`

See [docs/PHASES.md](docs/PHASES.md) for the full plan and everything below
as it is built.

---

# Development Philosophy

```text
1. Make it work
       ▼
2. Make it correct
       ▼
3. Make it reliable
       ▼
4. Make it observable
       ▼
5. Make it fast
```

One phase = one commit to `master`, marked done only after
`make test`, `make analyse`, `make format-check` pass in the container.

No benchmark in this project is ever presented as a universal truth - every
result records its environment (PHP version, OS, CPU, container limits, JIT
and OPcache state, allocator).

---

# What This Project Is Not

Not a production memory manager, not a Redis, not a shared-memory database,
not a complete IPC framework, not a general-purpose process manager, not a
universal benchmark suite. The code may contain simplified synchronization,
incomplete failure recovery and deliberately unsafe FFI examples. Do not use
it in production without a separate security, correctness, reliability and
performance review.

Unsafe experiments (double free, use-after-free, buffer overflow, invalid
mappings, corrupted shared-memory headers) run only inside disposable
containers and never on production.

---

# Concepts Explored

## Computer Science

* Reference counting · Copy-on-Write · garbage collection
* hash tables, packed vs associative arrays
* producer/consumer, ring buffers, message framing, backpressure

## Operating Systems

* `fork()`, process lifecycle, zombies, signals, `waitpid`
* virtual vs resident memory, page tables, page faults
* shared vs private pages, PSS, RSS, private dirty
* SysV shared memory, semaphores, message queues
* `mmap`, file-backed and anonymous mappings, `msync()`

## Native Integration

* FFI, `malloc`/`free`, ownership, buffer bounds, undefined behavior

## Concurrency

* multi-process IPC, synchronization, race conditions, atomicity

---

# Related Projects

### [PHP Worker Pool](https://github.com/Researcher86/php-worker-pool)

A sibling playground: persistent forked PHP workers, IPC over socket pairs,
a Unix domain socket front door, and a single-threaded event-driven Master.
The two projects share tooling conventions - and `php-memory-lab`'s IPC and
memory-reporting primitives are the foundation concepts a worker-based
runtime is built from.

### PHP Systems Laboratory

`php-memory-lab` is part of the larger `php-systems-lab` exploration:

| Project | Main focus |
|---|---|
| `php-worker-pool` | managing reusable worker processes |
| `php-memory-lab` | memory, virtual memory, processes, IPC, native memory |
| `php-job-queue` | reliable asynchronous job processing |
| `php-mini-cache` | event-driven in-memory server |
| `php-mini-http-server` | HTTP server and event loop fundamentals |

---

## License

MIT