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

## Reproduce

```bash
make experiment ARGS="process:fork"
make experiment ARGS="process:fork-with-data"
make experiment ARGS="process:multiple-forks"
make experiment ARGS="process:lifecycle"
```

Every printed `Context:` line is deliberate: values depend on the PHP
version, the allocator, and the container limits — not universal truth.
Phase 4 continues this document with the write-side measurements (single
write, many writes, full rewrite, per-region writes).