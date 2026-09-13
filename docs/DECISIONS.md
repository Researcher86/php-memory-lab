# Decisions — PHP Memory Lab

A record of meaningful technical and process decisions, with the reason each
was made. New decisions get appended with a date.

## 2026-09-12 — Phase 0 is committed; stack is PHP 8.5

- PHP is `^8.5`, matching the sibling project `php-worker-pool`, not the
  `^8.4` first suggested in the plan.
- The container image is `php:8.5-cli` with `pcntl`, `posix`, `sockets`,
  `sysvmsg`, `sysvsem`, `sysvshm`, `ffi`, plus `Xdebug` in
  `start_with_request=trigger` mode so a debugger only attaches when asked.
- Initial commit `Initialize php-memory-lab project` contains the verified
  Phase 0 skeleton (Composer, Dockerfile, docker-compose, Makefile, PHPUnit,
  PHPStan level 8, PHP-CS-Fixer).

## 2026-09-12 — Namespace is `App\`, not `MemoryLab\`

- Autoload: `App\` → `src/`, `App\Tests\` → `tests/`.
- No `MemoryLab\` prefix survives anywhere.

## 2026-09-12 — No `extension_loaded()` runtime checks

- The container is the guarantee that the required extensions exist; a
  `PlatformRequirementsTest` that asserts
  `extension_loaded()` for each extension was removed.
- Platform-dependent engine tests (fork, SysV, FFI, mmap) either run in the
  container or skip when the *underlying facility* (e.g. `/proc`) is
  unavailable — they do not probe extensions at runtime.

## 2026-09-12 — LICENSE removed

- The project ships without a `LICENSE` file; `composer.json` still declares
  `"license": "MIT"`.

## 2026-09-12 — `PLAN.md` folded into `docs/PHASES.md` and deleted

- The long plan document no longer lives at the repository root. Its content
  was folded into `docs/PHASES.md`, which now follows the worker-pool format
  (Final Architecture, Core Principles, phase sections with Goal / Tasks /
  Definition of Done / Tests).
- `docs/PHASES.md` is the single build journal: every completed phase is one
  commit to `master`, and work is marked done only after it passes in the
  container (`make test`, `make analyse`, `make format-check`).

## 2026-09-12 — Tooling mirrors `php-worker-pool`

- `docker-compose.yml` gained a named service container and matches the
  sibling project's compose conventions.
- `Makefile` was rewritten to the worker-pool style: `up`/`down`/`build`,
  `shell`, `htop`, `install`, `test`, `analyse`, `format`,
  `format-check`, plus `experiment`/`benchmark` with `-debug` variants
  (Xdebug via `XDEBUG_TRIGGER=1`).
- CI (`github/workflows/ci.yml`) runs on GitHub Actions with the required
  extensions via `shivammathur/setup-php`, then `composer test`,
  `composer analyse`, `composer format:check`.
- `README.md` was rewritten in the worker-pool style (30-second demo, what
  it does, mechanisms → files, documentation, development, roadmap).

## 2026-09-12 — Project root layout

- `src/` and `tests/` hold no empty `.gitkeep` placeholders; the directory
  they were keeping non-empty now contain real files (`src/Memory/`,
  `tests/`).
- Experiments live at `experiments/NN-name/`, benchmarks at `benchmarks/`,
  measured results in `var/results/`, all per the plan and unchanged by the
  restructure.

## 2026-09-13 — Phase 5: no `Channel` interface, and the framer does not reassemble

- `SocketChannel` is the only channel, and the ring buffer of Phase 7 will
  have different semantics rather than the same ones over another transport,
  so the one-implementation `Channel` interface was dropped instead of being
  carried forward.
- `MessageFramer` keeps only `encode()` and `decodeHeader()`. Reassembling a
  frame requires knowing whether the rest of the payload has arrived, which
  only the owner of the stream knows, so that buffer lives in
  `SocketChannel`. A framer with an unused `decode()` half is a trap: it
  looks like the code path the channel uses and is not.
- `send()` blocks and there is no non-blocking write. The block is the
  measurement - `experiments/06-process-ipc/backpressure.php` reports how
  much the kernel buffers absorb before a producer feels its consumer.
- `MSG_NOSIGNAL` is passed through a local variable because PHPStan's
  `socket_send()` stub does not list it among the allowed flag constants,
  though PHP defines it and Linux honours it. Without the flag, writing to a
  departed peer raises SIGPIPE and kills the process before the error can be
  reported.

## 2026-09-13 — Phase 6: SysV resources are cleaned up by key, not by object

- `SharedMemorySegment::detach()` gives up the handle, and a wrapper without a
  handle cannot remove the segment behind it. Tests and experiments therefore
  keep the *key* and call `attach($key)->destroy()` on the way out. The test
  suite leaked one 64 KiB segment per run before this, which is the same
  mistake that fills `ipcs -m` on a long-lived machine.
- `Semaphore::synchronized()` is the intended entry point; `acquire()` and
  `release()` stay public because the counter experiment needs to time the
  wait separately from the critical section.
- The `$autoRelease` argument is kept and exposed as a readonly property, but
  the documentation says what it actually does: PHP passes `SEM_UNDO` on every
  acquire regardless, so process death is always covered, and `$autoRelease`
  only governs release at PHP's request shutdown. An earlier draft of the
  experiment claimed the opposite and was corrected against a direct
  measurement.
- The race experiment tolerates `SharedMemoryException` inside the
  unsynchronized loop rather than crashing. Past four writers the counter
  variable really does disappear mid-run, and a crash there would have hidden
  the most interesting result behind a stack trace.

## 2026-09-13 — Phase 7: `ext-shmop` for raw bytes, and the lock is the cost

- `ext-shmop` was added to the Dockerfile, `composer.json` and CI. The ring
  buffer needs a byte layout it controls — magic, version, positions, fixed
  slots — and `shm_put_var()` serializes each value into a variable directory
  of its own, which is exactly the layer being replaced. The sysvshm wrapper
  of Phase 6 stays as it is; the two coexist.
- `RingBuffer` reads its whole header in one `shmop_read()` and writes the
  mutable tail back in one `shmop_write()`. The first draft read each field
  separately, which cost ~28 calls per push/pop pair and made the buffer twice
  as slow as it needed to be; committing sixteen bytes at once also means
  positions, count and the busy flag can never be observed half-updated.
- The semaphore is kept despite the measurement showing it to be the dominant
  cost, because the plan's constraint for this phase is a synchronized buffer
  and a lock-free one would be correct only for exactly one producer and one
  consumer, on an atomicity guarantee PHP does not make. The unsynchronized
  variant is measured *inside* `ring:throughput` as a control rather than
  offered as an API.
- A crash mid-update is detected and never repaired. The header records the
  pid inside a transaction; a non-zero value on entry means a process died
  between two writes, and the only honest answers are to refuse the buffer or
  to format a new one and accept that its contents are gone.

## 2026-09-13 — Phase 8: every FFI call lives in one file

- `src/Native/Libc.php` is the only place in the project that calls a C
  function. FFI resolves those at runtime, so each one is invisible to static
  analysis; wrapping them in typed static methods means the single
  `ignoreErrors` entry PHPStan needs can name one file and one identifier
  (`method.notFound`) instead of the whole `src/Native` directory.
- Pointers are compared against `MAP_FAILED` by reading their address out of a
  one-element `void*[1]` array, because casting a pointer to an integer type
  segfaults this PHP build. The alternative — trusting that `mmap()` only
  fails in ways that return null — is wrong: it returns the address -1.
- `MappedFile::open()` always `ftruncate()`s the file to the mapping size.
  Mapping past the end of a file is allowed by the kernel and then kills the
  process with SIGBUS on first touch, which is a considerably worse way to
  learn that the file was short.
- The SIGBUS demonstration runs in a forked child. It is the first experiment
  in the project whose expected outcome is a dead process, and the project
  rule about unsafe experiments is what decides where it runs.

## 2026-09-13 — Phase 9: `ext-ffi` declared, and the bounds check is three comparisons

- `ext-ffi` joined the `require` block in `composer.json`. It had been in the
  image and in CI since Phase 0 but was never declared as a platform
  requirement, which only became wrong once Phases 8 and 9 made it
  load-bearing. `composer.lock` was refreshed with it.
- `FfiBuffer` validates with `$offset > $size || $length > $size - $offset`
  rather than `$offset + $length > $size`. The sum overflows for a large
  offset and wraps negative, which would make the guard permit exactly the
  access it exists to stop; `testAnOffsetThatWouldOverflowTheBoundsCheckIsRefused`
  passes `PHP_INT_MAX` to keep it that way.
- `FfiBuffer::free()` is idempotent and `__destruct()` calls it. The
  underlying `free()` is neither of those things, and a buffer whose last
  reference is dropped would otherwise leak with nothing in the PHP counters
  to show for it. Explicit `free()` remains the intended route; the destructor
  is a backstop.
- The unsafe ownership experiments stay out of the automated test suite and
  run each case in a forked child. Several of them end the process by design,
  which is the result being demonstrated rather than a problem to work around.

## 2026-09-13 — Phase 10: the iteration count belongs to the benchmark

- A `Benchmark` carries its own `iterations`, because the right number is a
  property of the operation: a socket round trip needs twenty thousand to rise
  above timer noise and a 100,000-element allocation needs fifty. `--iterations`
  exists as an override for quick runs, not as the normal way to set it.
- `operationsPerSecond()` is derived from the median repetition rather than
  the mean. On a shared machine one repetition routinely loses its CPU, and
  the mean follows it; `TimingsTest` asserts exactly that difference.
- Percentiles are nearest-rank and not interpolated. With five to nine
  samples, interpolating invents precision the measurement does not have.
- `Timings` keeps its samples where `php-worker-pool`'s `DurationStat`
  deliberately does not. The constraint is opposite: a benchmark holds a
  handful of floats for a few seconds, a Master would hold an unbounded stream
  for the life of the process.
- Benchmark progress goes to stderr. `--format=json > file` has to stay valid
  JSON.

## 2026-09-13 — Phase 11: experiments are descriptors, and the list is the directory

- Every file under `experiments/` now returns an `Experiment` — name,
  description, the options it honours, and a closure — instead of executing at
  require time. That is what lets `Registry` discover them by requiring all
  thirty-two cheaply: a descriptor allocates nothing, and everything that does
  (a segment, a mapping, a forked child) lives inside the closure.
- The hard-coded command list in `bin/experiment` is gone. It had drifted from
  the directory twice in five phases, and `RegistryTest` now loads the whole
  directory on every test run, so a broken descriptor fails in the suite
  rather than the first time that one experiment is run.
- `experiments/run-helpers.php` and its global functions are replaced by
  `Output`. A forked child writing through an instance it inherited is visible
  in the code; a forked child writing through a global is a thing to remember.
- An experiment refuses an option it does not read, and the check lives in
  `ExperimentRunner` so that all of them refuse in the same words. Silently
  accepting `--children=8` is how a run gets reported under a configuration it
  never had.
- `--format=json` suppresses the prose rather than capturing it. Several
  experiments write from several processes at once, and interleaving that
  inside a JSON string would produce neither readable output nor valid JSON.
