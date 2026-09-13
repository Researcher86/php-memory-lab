# PHP Memory Model: What a PHP Value Actually Costs

Phase 2 exists to answer the question every "why is my queue/worker/cache fat?"
investigation starts with: **what does a PHP value weigh, and why?**

The short version, in one paragraph: PHP stores every value in a 16-byte
container called a **zval**, shares values with a **reference count** so that
assignment is cheap, defers copies until the first write (Copy-on-Write),
stores arrays either as a flat **packed** table of zvals or as a **hash
table**, keeps freed memory in **arenas** instead of returning it to the
kernel, and detects the one case plain reference counting cannot solve —
**cycles** — with a separate garbage collector. Every one of those claims
below is paired with a measurement from `experiments/`.

## The zval: 16 bytes of reality

Every integer, float, string, array, object and reference in PHP is carried
in a `zval` structure. On a 64-bit build one zval occupies **16 bytes**:

| field | size | contents |
|---|---|---|
| `value` | 8 bytes | the raw value or a pointer to heap storage |
| `u1/u2` | 8 bytes | type, reference count, flags |

The important consequence: *an array literal with 1,000,000 integers costs
about 16 MB before the integers themselves are touched.*

```text
$ make experiment ARGS="memory:packed-arrays"
range(1, 1_000_000):      PHP usage delta  +16.00 MiB
append loop ($a[] = $i):  PHP usage delta  +16.00 MiB
array_fill(0, N, 0):      PHP usage delta  +16.00 MiB
```

1,000,000 zvals × 16 bytes = 16 MiB exactly, regardless of how the array was
built. `range()` preallocates and fills; the loop grows the array
geometrically; both land on the same packed layout with the same bill.

## Reference counting and Copy-on-Write

`$b = $a;` must not copy anything. PHP does what every modern interpreter
does: it shares the zval and bumps a reference count, then **separates** the
two variables only when one of them is actually written.

```text
$ make experiment ARGS="memory:strings"
$a = str_repeat('a', 1 MiB);
$b = $a;                    # copy assignment:  +192 B  (just refcount++)
$b[0] = 'z';                # first write:      +1.00 MiB  (real copy now)
```

The `+192 B` is the cost of the shared-string bookkeeping; the copy only
appears at the first write, on the separation of a 1 MiB buffer. This is the
mechanism that makes function-call argument passing and nested loop
assignments cheap, and it is the reason "I copied a big string" is a wrong
diagnosis unless a write actually happened.

## Strings: payload + a small header

A string reads as a `zval` pointing at a heap buffer holding:

```text
[ string header (len, hash, gc info) ] [ payload bytes ] [ NUL ]
```

The overhead is a fixed header plus alignment — a few dozen bytes per string.
Building a large string by concatenation mostly grows that single buffer.

```text
$ make experiment ARGS="memory:strings"
grown by .= 10k x 1k:   PHP usage delta  +9.77 MiB
```

10,000 concatenations of 1 KiB produce a 10 MiB payload; PHP's allocator
growth strategy costs less than 2.5% over the payload. The empty and short
(32-byte) strings cost roughly their own size plus the header; a 1 MiB string
measured `+1.00 MiB`.

## Packed vs associative arrays: the layout decision

An array is either one of two layouts:

- **packed** — integer keys `0..n-1`, stored as one flat C array of zvals.
  The key is the slot index; no key storage, no hashing, zero waste.
- **associative (hash table)** — every element carries a bucket (hash,
  key, next) + a zval. Needed for string keys or any non-contiguous integer
  keys.

```text
$ make experiment ARGS="memory:associative-arrays"     # N = 100_000
string keys  "key_$i" => $i:   PHP usage delta  +8.81 MiB   (~88 B/elem)
dense integer keys 0..N-1:     PHP usage delta  +2.00 MiB   (~16 B/elem)
sparse integer keys $i*10:     PHP usage delta  +5.00 MiB   (~50 B/elem)
```

The same element count costs 16 B (packed) vs ~88 B (string keys, which must
also store a copy of each key string) vs ~50 B (sparse integer keys, which
still need buckets). The bucket storage and per-key entries are the price of
not being a continuous packed sequence.

## Sparse arrays: the layout switch is one-way

The engine switches a packed array to a hash table the moment the keys stop
being dense `0..n-1`. The switch is a *pessimistic* one-way door:

```text
$ make experiment ARGS="memory:sparse-arrays"     # N = 100_000
dense keys 0..N-1:                 +2.00 MiB   (packed)
holes keys 0,2,4,...:              +5.00 MiB   (hash table)
built dense, then one gap added:   +5.00 MiB   (a single hole demotes it)
```

A single `$a[$N * 100] = 'far away'` on an otherwise dense array flips the
entire structure to a hash table. If you build an array dense and then poke a
hole into it, you pay the associative price for every element from then on.
Inserting in a non-packed key order (or a `foreach`-style append after a
hole) has the same effect.

## Nested arrays: the table shape matters

Nested arrays are a tree of hash tables. The dominating cost is **how many
distinct arrays** exist, not how many elements:

```text
$ make experiment ARGS="memory:nested-arrays"   # ROWS=10k, COLS=10
10k rows, each range(10):                +3.84 MiB   (10k distinct arrays)
10k rows sharing one frozen row:         +260 KiB    (10k references, 1 array)
one flat array of 100k ints:             +2.00 MiB   (packed, no nesting)
```

"Rows share one array" costs almost nothing because every row element is a
reference to the same zval. A matrix worth holding entire should stay flat:
one packed array beats ten thousand small arrays, and sharing frozen rows
beats duplicating them.

## Objects: header per instance, property table per class

Every object pays a fixed per-instance header (class pointer, handlers table,
property table, GC info). A **typed** DTO, however, stores its declared
properties in a compiled property table shared per class, not in a per-object
hash table — so a typed object can be *cheaper* than an associative array
holding the same fields:

```text
$ make experiment ARGS="memory:objects"     # N = 100_000
N assoc arrays  a,b,c,d:               +37.86 MiB   (~379 B/elem, 4 hash buckets each)
N typed DTO objects a,b,c,d:           +13.68 MiB   (compiled property table)
N empty stdClass:                       +5.82 MiB   (header, dynamic props)
N empty arrays:                          +2.00 MiB
```

The headline result: for a fixed four-field row, **DTO objects use ~36% of
the memory of associative arrays**. The "dynamic" flexibility of array rows
is paid with per-key hash buckets on every single row.

## Garbage collection: refcounts are enough until they are not

Plain reference counting releases the moment the count hits zero:

```text
$ make experiment ARGS="memory:gc"
acyclic: 1M ints in one array
  allocated                         +16.00 MiB
  after unset()                     -15.93 MiB   (immediately, no collector)
```

A **cycle** (`$a['self'] = &$a;`) can never reach zero on its own, so the
memory survives until the garbage collector delivers it:

```text
cyclic: N self-referencing arrays, automatic GC disabled while building
  after building N cycles:                 +54.17 MiB
  after gc_collect_cycles():               -54.17 MiB
  (gc_collect_cycles() freed 100000 root buffers)
```

(The automatic collector was disabled during the build — with it on, PHP
purges cycles whenever the root buffer fills, which would hide the trap.) A
long-running worker that accumulates self-referencing structures grows
without bound until cycle collection runs, either automatically at its
threshold or explicitly via `gc_collect_cycles()`.

## Arenas: why freed memory stays in RSS

None of the deltas above are "returned to the kernel". PHP's allocator keeps
freed chunks in arenas for reuse, so `unset($bigArray)` drops
`memory_get_usage()` back to baseline while `VmRSS` stays at its peak — the
kernel does not reclaim the pages until the allocator releases whole chunks.
See `docs/php-memory-vs-rss.md` for the full story; the practical planning
rule from Phase 1 applies twice over for strings and arrays: **plan for peak,
not residue**, and judge a worker by `memory_get_usage()` deltas plus
`Private_Dirty`, not raw RSS.

## Practical rules

1. **A zval is 16 bytes.** Count rows × zvals: 1M integers ≈ 16 MiB packed.
2. **Copy-on-Write defers copies to the first write.** Cheapest way to "copy"
   is not to write.
3. **String payloads scale almost perfectly**; the header is tiny and the
   allocator growth overhead is < 3%.
4. **Prefer packed arrays.** Keep integer keys dense `0..n-1`; one sparse key
   silently re-lays out the whole array into a hash table.
5. **Flat beats nested.** Fewer, bigger arrays beat many small ones; share
   frozen rows instead of duplicating.
6. **DTOs beat assoc rows for fixed schemas.** Compiled property tables beat
   per-row hash buckets — measure before assuming arrays are cheaper.
7. **Cycles leak until the collector runs.** Unreachable self-references are
   invisible to `unset()`; long-running workers should track them.
8. **Freed memory stays in RSS.** The engine's arenas keep the pages; watch
   peak and `Private_Dirty`, not just RSS.

## Reproduce

```bash
make experiment ARGS="memory:strings"
make experiment ARGS="memory:packed-arrays"
make experiment ARGS="memory:associative-arrays"
make experiment ARGS="memory:sparse-arrays"
make experiment ARGS="memory:nested-arrays"
make experiment ARGS="memory:objects"
make experiment ARGS="memory:gc"
```

Every printed `Context:` line is deliberate: the numbers depend on the PHP
version, the allocator, and the container limits, so this document reports
measured values and their environment — not universal truth.