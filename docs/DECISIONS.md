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
