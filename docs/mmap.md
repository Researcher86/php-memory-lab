# `mmap`: Memory That Is a File, and a File That Is Memory

Phase 8 reaches below the PHP allocator for the first time. There is no
`mmap()` in PHP and no way to get the file descriptor behind a stream, so the
whole phase runs through FFI into libc — which also makes it the first place
where a mistake is a signal rather than an exception.

## A mapping is a promise, not a read

`mmap()` reads nothing. It adds a region to the page table meaning "these
addresses come from that file" and returns. Nothing is fetched until something
touches a page:

```text
$ make experiment ARGS="mmap:lazy"
File: … of 256.00 MiB, page size 4.00 KiB, PHP memory_limit 128M

After mmap() of the whole file:
  VmSize +256.06 MiB   RSS +220.00 KiB   PHP usage +25.32 KiB   minor faults +0
```

The address space grew by the size of the file; the resident memory did not.
Two things follow, and the second is the one people trip over.

**A mapping is not subject to `memory_limit`.** 256 MiB mapped under a 128 MiB
limit, with no complaint, because the limit counts what the PHP allocator hands
out and a mapping is not that. `memory_get_usage()` will never report it
either. Same blind spot as `RssShmem` in
[shared-memory.md](shared-memory.md), and the same one FFI opens up again in
Phase 9.

**RSS follows what is touched, but not one page at a time:**

```text
  touched      1 pages ( 4.00 KiB): RSS  +1.00 MiB   minor faults     +1    1.00 MiB per fault
  touched     16 pages (64.00 KiB): RSS        0 B   minor faults     +0
  touched    256 pages ( 1.00 MiB): RSS        0 B   minor faults     +0
  touched  4,096 pages (16.00 MiB): RSS +15.00 MiB   minor faults    +15    1.00 MiB per fault
```

Fifteen faults for four thousand pages. The kernel reads ahead and maps whole
folios, so a sequential walk pays one fault per large run rather than one per
page — and the rows with no faults at all are pages that an earlier fault had
already mapped, which is why they still cost time but nothing else.

`munmap()` gives it all back at once: the RSS delta across map → touch →
unmap returns to noise. An unmapped region is gone from the process
immediately, unlike freed PHP memory, which the allocator keeps
([memory-model.md](memory-model.md)).

## Against reading the file

```text
file_get_contents() of the same 256.00 MiB (memory_limit raised to 512M to allow it):
  PHP usage +256.00 MiB   RSS +256.00 MiB   86.7 ms
```

The mapping paid for the 16 MiB it touched. The string paid for all 256 MiB,
because a PHP string has no way to be partly present — and needed the limit
raised before it was allowed to.

That makes a mapped file the right shape for a large index, a database page
cache, or any read-mostly dataset where the working set is a fraction of the
whole; and the wrong shape for something that will be scanned end to end
exactly once, where the readahead is the only thing mapping would have bought.

## One flag decides whether it is sharing or copying

```text
$ make experiment ARGS="mmap:modes"
MAP_SHARED
  the writer itself reads: MAP_SHARED was here
  another process reads:   MAP_SHARED was here
  the file on disk says:   MAP_SHARED was here

MAP_PRIVATE
  the writer itself reads: MAP_PRIVATE was here
  another process reads:   untouched original
  the file on disk says:   untouched original
```

`MAP_SHARED` writes go into the page cache, which *is* the file: every other
process mapping it sees them, with no flush and no message. `MAP_PRIVATE`
faults a private copy of the page on the first write — Copy-on-Write applied
to a file instead of to a `fork()`, and it accounts identically:

```text
  child, reading the same 16.00 MiB : RSS 29.63 MiB | PSS 14.37 MiB | Shared_Dirty  9.61 MiB
  parent, while the child maps it   : RSS 44.77 MiB | PSS 19.59 MiB | Shared_Dirty 25.48 MiB
  parent, after the child exited    : RSS 44.77 MiB | PSS 33.01 MiB | Shared_Dirty      0 B  | Private_Dirty 26.20 MiB
```

Two processes over one copy of the data. RSS counts the pages in both and
cannot express that; PSS halves them and can. When the child leaves, the
parent's share stops being shared — `Shared_Dirty` becomes `Private_Dirty`
without a byte moving. This is the same accounting as
[fork-and-cow.md](fork-and-cow.md), which is the point: `fork()` and
`MAP_PRIVATE` are the same mechanism seen from two directions.

## `msync()` is durability, not visibility

```text
msync() with dirty pages: 18.257 ms; immediately again, with nothing left dirty: 0.059 ms
```

The other process above saw every byte without one. Flushing matters for
surviving a power cut, not for being seen — readers are looking at the same
page cache the writer is writing to, so they were never behind. The 300×
difference between the two calls is the write-back itself; a clean `msync()`
is a syscall and a scan.

## A `MAP_SHARED` file is shared memory with a filename

Compared against Phase 6's SysV segment, it has the same properties minus the
serialization — it is genuinely raw bytes — and with a lifetime managed by the
filesystem rather than by `ipcs`. A stale mapping file is visible, greppable
and deletable by ordinary means, where a stale segment needs `ipcrm` and a
key nobody wrote down.

What it does *not* come with is any more synchronization than the segment had.
Two processes writing the same offsets race exactly as they did in
[shared-memory.md](shared-memory.md), and the same semaphore is the answer.

## The failures split in two

```text
$ make experiment ARGS="mmap:failures"
  read past the end      Access of 10 bytes at offset 8190 runs past the 8192-byte mapping
  write past the end     Access of 200 bytes at offset 8100 runs past the 8192-byte mapping
  negative offset        Negative offset (-8) or length (8)
  a zero-byte mapping    A mapping needs at least one byte
  unmapping twice        second call is a no-op; a second munmap() of the same address is not
  reading after unmap    The mapping of … was already unmapped

  child: file truncated to 4 bytes, mapping still 1.00 MiB - touching offset 512 KiB now
  child died from signal 7 (SIGBUS) - no exception, no errno, no stack trace
```

Everything computable in advance — offsets, lengths, whether the region still
exists — is refused with an exception. Everything else kills the process.

A mapping outlives the file's size. Truncate the file and the pages past the
new end are still in the page table with nothing behind them; touching one
raises `SIGBUS`, whose default action is death. There is no return value and
nothing to catch, which is why that demonstration runs inside a child that is
expected to die.

Two rules follow:

1. **The bounds check in PHP is not defensive programming, it is the only
   layer there is.** Past it, the next thing that notices a bad address is the
   MMU.
2. **Never truncate a file anyone has mapped.** Write a new file and rename it
   over the old one; existing mappings keep the old inode until they are
   dropped.

## Practical rules

1. **Map when the working set is smaller than the file.** Otherwise the read
   you avoided happens anyway, one fault at a time.
2. **Let the kernel manage the cache.** Mapped pages are evictable under
   pressure; a PHP string is not, and counts against `memory_limit` besides.
3. **`MAP_SHARED` for communication, `MAP_PRIVATE` for a cheap private view**
   of a file you intend to modify locally.
4. **Flush for durability, never for visibility.**
5. **Validate every offset and length before the access**, and keep the
   validation next to the mapping that knows its own size.
6. **Unmap explicitly.** The region is gone from RSS the moment you do, which
   is a stronger guarantee than anything the PHP allocator offers.

## Reproduce

```bash
make experiment ARGS="mmap:lazy"       # lazy loading, page faults, against file_get_contents()
make experiment ARGS="mmap:modes"      # MAP_SHARED vs MAP_PRIVATE, PSS across two processes, msync
make experiment ARGS="mmap:failures"   # bounds, double unmap, use after unmap, SIGBUS on truncation
```
