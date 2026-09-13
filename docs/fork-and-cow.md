# Fork and Copy-on-Write: What a Child Actually Inherits

Phase 3 lays the ground: what `pcntl_fork()` does to memory, and how parent
and child account for it. Phase 4 completes the CoW story — when the shared
pages become private. This document is the live explanation; it grows with
each phase.

## `fork()` is not a copy of bytes

`fork()` creates a child that starts from the *same* physical pages: the
child's address space is a page-table clone of the parent's, with every page
marked read-only in both. If nobody writes, parent and child share all memory
forever. The first write by either process faults the page and gives only
that process a private copy (Copy-on-Write). That is why a fork of a
memory-heavy process is cheap, and why "the child inherited my 16 MiB array"
costs ~nothing until someone touches it.

```text
$ make experiment ARGS="process:fork"
--- child (pid …) ---
  PHP usage: 1.38 MiB
  RSS:       15.57 MiB        ← the child's RSS mirrors the parent's mappings
  PSS:        7.40 MiB        ← but it only *owns* about half of them
  VmSize:    62.75 MiB        ← identical to the parent: same address space
fork() took 0.39 ms
```

Parent and child each run their own snapshot, and each records its own PID.
`VmSize` is identical — a fork inherits the whole virtual address space — but
the child's PSS is roughly half its RSS: the child shares most pages with the
parent, and PSS divides shared pages among sharers. RSS counts them fully in
both rows, so summing RSS across a process tree double-counts.

## Inherited data, no copy

```text
$ make experiment ARGS="process:fork-with-data"
parent allocated (1_000_000 ints):   PHP usage +16.00 MiB
parent while child lives:            RSS 44.68 MiB   PSS 22.08 MiB
child after fork (read-only):        RSS 31.76 MiB   PSS 15.47 MiB
parent after child exits:            delta 0
```

The child reads `$data[0] + $data[$count - 1]` — no page is copied by the
read. Both processes keep pointing at the same physical pages; the parent's
RSS does not double while the child lives. PSS of both processes sits well
below half of the sum of per-process RSS: the shared array is *one* physical
picture seen from two address spaces.

## The bill: page tables, not data

What a fork does consume is the child's own page-table hierarchy, its stack,
and anything paged in by *its* execution. With more children the parent's PSS
keeps falling while RSS stays flat and the children carry their own working
sets:

```text
$ make experiment ARGS="process:multiple-forks"
children  1 | forks 0.43 ms | parent RSS 28.69 MiB | parent PSS 14.01 MiB | children RSS  15.54 MiB
children  4 | forks 1.42 ms | parent RSS 28.75 MiB | parent PSS 11.12 MiB | children RSS  52.30 MiB
children 16 | forks 4.93 ms | parent RSS 28.75 MiB | parent PSS  9.44 MiB | children RSS 209.19 MiB
```

Fork time scales near-linearly per process (≈0.3–0.4 ms each), the parent's
own RSS barely moves, and its PSS drops toward half the shared footprint as
each new sharer splits another slice of the shared code and data pages.
"Approx total RSS" in the experiment output is a trap: it adds up per-process
RSS and double-counts everything shared.

## Lifecycle: exit, wait, zombies, signals

A child does not disappear when it exits; it becomes a **zombie** that holds
only its pid and exit state until the parent calls `waitpid()` (reaps it).
An unserviced child OR a supervisor that never waits leaks pids.

```text
$ make experiment ARGS="process:lifecycle"
1) normal exit  (exit(0)):  waitpid -> …, exit status 0
2) non-zero exit (exit(3)): waitpid -> …, exit status 3
3) zombie: child exits, parent delays waitpid:
   child still unreaped, /proc/<pid>/status: State:	Z (zombie)
   waitpid -> …, exit status 0
4) killed by signal (SIGTERM):
   waitpid -> …, terminated by signal 15     ← wifsignaled + wtermsig
```

Reading exit status: `pcntl_wexitstatus()` for a normal exit, `pcntl_wifsignaled()`
signals a kill, `pcntl_wtermsig()` names the signal. A zombie's `State` line in
`/proc/<pid>/status` reads `Z (zombie)` between child exit and parent reaping.

## Practical rules (Phase 3 groundwork)

1. **A fork does not copy memory** — it clones page tables. Data stays
   shared until written.
2. **Reads are free, writes are not.** The first write to a shared page is
   where memory actually "doubles".
3. **Summing RSS over a process tree double-counts.** Use PSS and the
   shared/private split from `smaps_rollup`.
4. **Fork time grows with pid count, not data size** — the data is *not*
   copied at fork time.
5. **Every child must be reaped** — an unreaped child is a zombie holding a
   pid, and the parent must `waitpid()` it off.

## Write side: RSS does not move, Private_Dirty does

CoW is invisible to RSS. A shared page stays resident whether it is shared
or private, so RSS does not change when one writer privatises it. The
signal lives in `smaps_rollup`: the writer sees `Private_Dirty` rise and
`Shared_Dirty` fall by the same amount. All Phase 4 numbers below are the
child's view after writing the inherited 1M-integer array (≈4 MiB of
pages).

### One element

```text
$ make experiment ARGS="cow:single-write"
child BEFORE writing one element: Shared_Dirty 25.59 MiB | Private_Dirty  604.00 KiB
child AFTER  writing $data[0] = 999: Shared_Dirty 25.55 MiB | Private_Dirty 644.00 KiB
child Private_Dirty delta: +40 KiB
parent verifies data still intact: $data[0] = 0   <- parent untouched
```

One write privatises the page(s) holding that bucket — tens of kilobytes,
not megabytes. Keyword: **ten of kilobytes, not the array**. The parent's
copy is untouched; the child sees `999`, the parent still sees `0`.

### One, 1k, 10k, 1M element writes

```text
$ make experiment ARGS="cow:many-writes"
[1 write]        time  1.91 ms | Private_Dirty +132.00 KiB  | Shared_Dirty -104.00 KiB
[~1_000 writes]  time  3.51 ms | Private_Dirty +4.03 MiB    | Shared_Dirty -4.00 MiB
[~10_000 writes] time  1.96 ms | Private_Dirty +528.00 KiB  | Shared_Dirty -500.00 KiB
[1_000_000 writes] time 51.43 ms | Private_Dirty +15.39 MiB | Shared_Dirty -15.36 MiB
RSS does not move in any row (COW keeps the page resident)
```

Cost is per **page** written, and pages contain many buckets: 1 write
touches the page that the first ~hundred buckets live on; 1k writes scatter
over ~1000 pages (≈4 MiB). Writes to buckets that share a page coalesce
into one split, which is why the 10k row pays only ~528 KiB. The buckets
are not copied one by one — a whole 4 KiB page is flung apart at the first
write into it.

### Rewrite strategy changes the price

```text
$ make experiment ARGS="cow:rewrite"
in-place: $data[$key] ++      time  39.02 ms | Private_Dirty +15.27 MiB
fresh:    $data = range(...)  time   3.58 ms | Private_Dirty +16.00 MiB
```

In-place edits the *shared* pages, so the kernel has to copy each one the
moment the child writes it (about 4 MiB of real page copies). Fresh
`range()` builds a brand-new private array in the child — no shared page is
ever touched; the writes all land on pages the child has always owned
(limiting: the freed shared pages are reused by the allocator, but no
existing shared page is *merely copied*). Data shape and work are similar;
the memory accounting could hardly be more different.

### Disjoint regions: the best of both worlds

```text
$ make experiment ARGS="cow:multiple-children"
parent BEFORE forking:  Private_Dirty 26.16 MiB | Shared_Dirty 0 B
each of 4 children wrote 250k buckets -> Private_Dirty ~4.40 MiB each
parent WHILE children rewrite disjoint regions:
  Shared_Dirty 25.54 MiB | PSS 14.03 MiB | RSS 44.68 MiB (flat)
parent AFTER all children exited: PSS 35.71 MiB (restored)
```

Give four children four disjunct quarters of the array and each child
privatises roughly a quarter (≈4.4 MiB private) — nobody re-copies data
another child already split, and the parent's `Shared_Dirty` barely moves.
Partitioned writes are the reason fork-from-a-warmed-service is so cheap:
each worker owns its slice of the shared tree.

### Rule updates from Phase 4

1. **RSS is the wrong meter for CoW.** Track `Private_Dirty`/`Shared_Dirty`.
2. **The tax is per page, not per element.** Buckets on one page share one
   split; ~128 buckets/page is why 1k writes ≠ 1k page copies.
3. **Read-fork stays free** — a child that only reads inherits ~zero
   `Private_Dirty` and shares every page.
4. **Design children to write disjoint regions** if they must grow their own
   view of a shared structure.

## The fork() → child-exits → parent-waits lifecycle

| stage | what is visible | who owns it |
|---|---|---|
| `pcntl_fork()` | page-table clone, zero data copies | parent + child share pages |
| child reads | shared pages, `Private_Dirty` flat | nobody new |
| child writes | first write splits one or a few pages | only the writer |
| child exits | becomes zombie (`State: Z`) | zombie holds pid + exit state |
| `pcntl_waitpid()` | child reaped; exit status readable | parent |

## Reproduce

```bash
make experiment ARGS="process:fork"
make experiment ARGS="process:fork-with-data"
make experiment ARGS="process:multiple-forks"
make experiment ARGS="process:lifecycle"

make experiment ARGS="cow:readonly"
make experiment ARGS="cow:single-write"
make experiment ARGS="cow:many-writes"
make experiment ARGS="cow:rewrite"
make experiment ARGS="cow:multiple-children"
```

Every printed `Context:` line is deliberate: values depend on the PHP
version, the allocator, and the container limits — not universal truth.