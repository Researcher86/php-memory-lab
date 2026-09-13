# FFI and Native Memory: Bytes Nobody Is Counting

Phase 9 allocates memory the PHP engine has never heard of. `malloc()` through
FFI returns an address and nothing else — no length, no type, no refcount, no
garbage collector, and no check on any access. Everything difficult about this
phase follows from that sentence.

## The engine cannot see it

```text
$ make experiment ARGS="ffi:allocation"
PHP memory_limit: 128M

malloc(256.00 MiB):
  PHP usage +15.10 KiB   RSS +220.00 KiB   VmSize +256.07 MiB
```

A 256 MiB allocation under a 128 MiB `memory_limit`, and `memory_get_usage()`
reports fifteen kilobytes — the PHP objects wrapping it. The limit governs the
PHP allocator; this is not the PHP allocator.

RSS has not moved either, for the same reason as in [mmap.md](mmap.md): an
allocation this size goes to `mmap()` inside libc, which reserves address
space and touches no page. Writing one byte per page is what costs:

```text
After writing one byte per page:
  PHP usage +192 B   RSS +256.00 MiB

After free():
  PHP usage +96 B   RSS -256.00 MiB
```

The whole 256 MiB goes back at once, because a large allocation *is* an
`mmap()` and `free()` unmaps it. Compare the PHP allocator, which keeps its
arenas ([memory-model.md](memory-model.md)): freeing a large PHP string
returns the memory to PHP, not to the OS.

## Nobody frees it for you

```text
64 x 1.00 MiB malloc'd through libc directly and never freed:
  PHP usage +224 B   RSS +64.25 MiB
```

Dropping the PHP variable freed nothing. The pointer was a number, and losing
it lost the only way to ever call `free()` on that address. There is no
refcount on native memory and no `__destruct()` unless you write one — which
`FfiBuffer` does, as a safety net rather than as the intended route.

Note which counter noticed: PHP usage moved by 224 bytes, RSS by 64 MiB. A
worker that recycles itself when `memory_get_usage()` crosses a threshold would
see nothing at all here, and grow until the OOM killer arrives.

## Four containers, one measurement

```text
$ make experiment ARGS="ffi:comparison"
container      |   alloc   write    read release  (ms) |   while held (PHP / RSS) | after release (PHP / RSS)
-----------------------------------------------------------------------------------------------------------
PHP string     |    0.35  177.52    0.46    0.04 | +16.00 MiB +16.22 MiB |  +3.41 KiB +220.00 KiB
PHP array      |    0.00    1.02    0.05    0.04 | +17.01 MiB +16.00 MiB |     +384 B        0 B
FFI buffer     |    0.80    0.96    0.74    0.03 | +38.19 KiB +16.00 MiB | +38.20 KiB        0 B
mapped file    |    0.66    6.14    0.86    0.35 | +24.02 KiB +16.00 MiB | +23.98 KiB        0 B
```

The same sixteen megabytes, written and read in 64 KiB blocks.

**Only the PHP containers appear in the PHP column.** The FFI buffer and the
mapped file hold exactly as much data and the engine has no idea they exist,
which makes RSS the only column that describes all four.

**The PHP string is slow to write for a structural reason.** There is no
in-place block write into a PHP string, so every partial update copies all
16 MiB. The array avoids that by never having one big buffer — and therefore
never having one contiguous region either, which is exactly what you cannot
hand to a C function, a socket's `sendfile`, or a mapped file.

**The array's 17.01 MiB** is the data plus the hash table holding 256 entries.

## The mistakes, and who reports them

```text
$ make experiment ARGS="ffi:unsafe"          # container only
  allocate and free once         finished normally - nothing noticed
free(): double free detected in tcache 2
  free the same pointer twice    killed by signal 6 (SIGABRT - the allocator caught it)
  write after free               killed by signal 11 (SIGSEGV - an address that is not mapped)
  read after free                finished normally - nothing noticed
  write 4 KiB into 64 bytes      finished normally - nothing noticed
  overflow, then allocate again  finished normally - nothing noticed
```

Every case runs in its own forked child, because several of them end the
process. The result worth keeping is how little the severity of a mistake has
to do with whether anything reports it:

- A **double free** is caught instantly and by name — glibc keeps enough
  bookkeeping to recognise it.
- A **write after free** hit an address the allocator had already returned to
  the kernel, so the MMU stopped it. On a busier heap the same write lands in
  memory that now belongs to something else and succeeds.
- A **read after free** finished quietly, returning whatever those bytes have
  become. In a longer-lived process that is somebody else's data — the shape
  of a vulnerability rather than of a crash.
- A **4 KiB write into a 64-byte allocation** was not reported by the write,
  nor by the `free()` of the corrupted chunk, nor by sixty-four allocations
  afterwards. The worst mistake in the list is the one nothing noticed.

None of this is visible from PHP. There is no exception, no error handler, and
no diagnostic beyond a line glibc writes to stderr on its way out.

```text
The same three mistakes through FfiBuffer:
  free twice                     allowed, and survived - the wrapper made it safe
  use after free                 refused: The buffer was already freed
  write past the end             refused: Access of 4096 bytes at offset 0 runs past the 64-byte buffer
```

## What a safe wrapper actually is

`FfiBuffer` is not safe because it handles errors. It is safe because it makes
the dangerous states unreachable:

1. **The length lives in the PHP object**, because the allocation has never
   known how big it is. Every check is a comparison of numbers, done *before*
   the access — there is nothing after the access that would notice.
2. **The bounds check is three comparisons, not one.** `$offset + $length >
   $size` overflows for a large offset and wraps negative, turning the guard
   into a guarantee of exactly the access it exists to prevent. It is written
   as `$offset > $size || $length > $size - $offset` instead, and there is a
   test that passes `PHP_INT_MAX`.
3. **`free()` is idempotent**, which the underlying `free()` emphatically is
   not. The pointer is nulled, so a second call is a no-op and every later
   access throws instead of dereferencing a freed address.
4. **A destructor as a backstop.** Not the intended route — explicit `free()`
   is — but a buffer that goes out of scope should not leak invisibly.

## ABI is a contract nobody checks

`FFI::cdef()` declarations are taken on trust. A wrong signature — `int` where
the library has `long`, a missing `const`, the wrong argument order — is not
an error. It is a call made with the wrong stack layout, and what happens next
is undefined in the full sense.

This lab keeps every C declaration in one file,
[`src/Native/Libc.php`](../src/Native/Libc.php), for that reason and one
other: FFI resolves those functions at runtime, so each call is invisible to
static analysis. Confining them to a single class means the rest of the
project stays analysable, and PHPStan needs one narrowly scoped exception
rather than a blanket one.

The same file is also where platform assumptions are written down. `O_RDWR`,
`PROT_READ`, `MAP_SHARED` and the rest are numbers from Linux headers, not
values PHP can discover — except the page size, which *is* asked for
(`sysconf`) because it is a property of the running kernel.

## Practical rules

1. **Free explicitly, and own the lifetime in one place.** A destructor is a
   backstop, not a plan.
2. **Validate before every access**, against a length the PHP side is holding.
3. **Never compute a bound with an addition that can overflow.**
4. **Watch RSS, not `memory_get_usage()`,** in any process that uses FFI,
   `mmap` or shared memory. The engine's counter is blind to all three.
5. **Keep C declarations in one place** and treat them as the ABI contract
   they are.
6. **Unsafe experiments run in disposable containers,** in child processes,
   and never anywhere that matters.

## Reproduce

```bash
make experiment ARGS="ffi:allocation"   # 256 MiB under a 128M limit, and a leak PHP cannot see
make experiment ARGS="ffi:comparison"   # PHP string vs array vs FFI buffer vs mapped file
make experiment ARGS="ffi:unsafe"       # double free, use-after-free, overflow - contained in children
```
