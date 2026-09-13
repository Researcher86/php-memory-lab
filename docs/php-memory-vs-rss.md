# PHP Memory vs RSS: Two Views of the Same Process

Phase 1 exists to answer one question before anything else gets measured:
when PHP says "I use N bytes" and the OS says "the process holds M bytes",
who is right?

Both are, about different things.

## The two counters

### `memory_get_usage()` — the PHP engine's view

Counts bytes the **engine's allocator** (`emalloc`/`efree`) has handed out for
zvals, hash tables, strings and objects — everything the runtime manages
itself. `memory_get_usage(true)` counts the larger, real allocator footprint
(the malloc chunks PHP requested from the system); the default
`memory_get_usage()` gives the more granular per-allocation figure.

This number goes **down** when memory is freed: `unset($bigArray)` shrinks
it, because ownership is explicit (reference counting) rather than garbage
collected.

It knows nothing about:

- native buffers you hand over to `ffi` or `shmop` without telling PHP,
- the binary, libraries, stack, page tables,
- memory that was freed and then returned to the kernel vs kept in an arena.

### RSS — the kernel's view

Resident Set Size (`VmRSS` in `/proc/<pid>/status`) is the number of
**physical pages currently mapped into the process**. The kernel has no idea
what those pages contain — a page full of freed-but-still-mapped arena bytes
counts exactly like a page of live objects.

RSS goes **up** on first touch, and is deliberately slow to come **down**:
pages get returned to the kernel only when the allocator releases whole
chunks (`munmap`) or the kernel reclaims them under pressure. A process that
allocs 100 MiB and frees it can keep most of that RSS indefinitely — the
pages are mapped, zero-cost for PHP, and the kernel would rather you reused
them than round-tripped the allocator.

This is exactly what `experiments/01-memory-basics/empty.php` shows:

```text
Before:
  PHP usage:  0.4 MiB
  RSS:       12.8 MiB
  Private:    1.8 MiB

After allocation (1,000,000 integers):
  PHP usage: 22.1 MiB
  RSS:       34.6 MiB
  Private:   21.8 MiB

After cleanup (unset):
  PHP usage:  0.4 MiB     ← PHP gives it back
  RSS:       34.5 MiB     ← the kernel keeps the pages
  Private:   21.7 MiB
```

`memory_get_usage()` returns to baseline; RSS stays roughly where the peak
was. No leak — the pages are idle, mapped, anonymous — but a monitoring
system that only watches RSS reports a "leak" until the process exits.

## Where each number comes from

| Metric | Source | Meaning |
|---|---|---|
| `memory_get_usage()` | PHP allocator | live engine-owned bytes |
| `memory_get_usage(true)` | PHP allocator | malloc footprint the engine holds |
| `VmRSS` | `/proc/self/status` | resident mapped pages (shared or not) |
| `RssAnon` / `RssFile` / `RssShmem` | `/proc/self/status` | what backs the resident pages |
| `VmSize` | `/proc/self/status` | the whole virtual address space |
| `Pss` | `/proc/self/smaps_rollup` | RSS with shared pages divided across sharers |
| `Private_Dirty` / `Shared_Dirty` | `/proc/self/smaps_rollup` | who owns the pages |

The reporting layer (`MemoryReporter`) records PHP usage **and** `VmRSS`
**and** `Pss` on every snapshot, so no measurement in this lab ever reasons
from one view alone.

## Why RSS double-counts shared pages

After `fork()`, parent and child share physical pages (Copy-on-Write). Each
process lists those pages fully in its own `VmRSS`:

```text
         parent RSS     child RSS    sum      truth
shared   20 MiB         20 MiB       40 MiB   20 MiB of RAM
```

PSS fixes that by charging each sharer its proportional share:

```text
rmap:      20 MiB / 2 sharers
parent PSS: +10 MiB   child PSS: +10 MiB   sum 20 MiB ✔
```

So when reasoning about a fork tree, use `Pss` (the honest per-process share)
and the `Shared_*` / `Private_*` split; when reasoning about a single
process's footprint in isolation, `VmRSS` is the number you want.

## Practical rules

1. **One process, "how much RAM does it take?"** → `VmRSS`.
2. **"What did this PHP code allocate?"** → `memory_get_usage()` deltas.
3. **Process tree (fork/workers)?** → `Pss` + `Private_Dirty`, never raw RSS.
4. **Freeing memory does not drop RSS** — plan for peak, not residue.
5. **Compare like with like** — a `MemorySnapshot` carries all of them, and a
   `MemoryDiff` is only meaningful between two snapshots from the same
   process/environment.

## Reproduce

```bash
make experiment ARGS="memory:empty"
```

The printed `Context:` line is deliberate: every value depends on the PHP
version, the allocator, and the container limits, so experiments state their
numbers and their *environment* — never a universal truth.