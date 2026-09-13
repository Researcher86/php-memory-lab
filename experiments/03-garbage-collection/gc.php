#!/usr/bin/env php
<?php

declare(strict_types=1);

require \dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../run-helpers.php';

experiment_start('garbage collection: refcounts, cycles, gc_collect_cycles');

/*
 * PHP frees memory by reference counting: unset() drops one reference, and
 * at zero the zval is released immediately. Cheap and immediate for acyclic
 * structures. A self-referencing structure (a cycle) can never reach zero on
 * its own, so it survives until the garbage collector purges it.
 *
 * The automatic collector normally fires as root buffers fill up, which
 * would hide the effect. gc_disable() turns only that off; manual
 * gc_collect_cycles() still works, so the experiment can show the "before /
 * after collection" numbers explicitly.
 */
$reporter = experiment_reporter();
$N = 100_000;

/*
 * Acyclic: one array of 1M ints. unset() returns the memory immediately -
 * plain refcounting, no GC pass involved.
 */
experiment_note('acyclic: 1M ints in one array, freed by unset()');
$before = $reporter->snapshot();
$value = \range(1, 1_000_000);
$afterAlloc = $reporter->snapshot();
experiment_delta('allocated', $reporter->diff($before, $afterAlloc));

unset($value);
$afterUnset = $reporter->snapshot();
experiment_delta('after unset()', $reporter->diff($afterAlloc, $afterUnset));

/*
 * Cyclic: N arrays, each referencing itself, dropped one by one. With the
 * automatic collector disabled the garbage keeps piling up in the root
 * buffer, and only gc_collect_cycles() returns that memory.
 */
\gc_disable();

experiment_note('cyclic: N self-referencing arrays, automatic GC disabled during the build');
$before = $reporter->snapshot();
for ($i = 0; $i < $N; ++$i) {
    $a = ['payload' => \str_repeat('x', 128)];
    $a['self'] = &$a; // cycle: refcount can never reach zero on its own
    unset($a);
}
$afterBuild = $reporter->snapshot();
experiment_delta('after building N cycles (GC off)', $reporter->diff($before, $afterBuild));

$recollected = \gc_collect_cycles();
$afterGc = $reporter->snapshot();
experiment_delta('after gc_collect_cycles()', $reporter->diff($afterBuild, $afterGc));
experiment_note(\sprintf('gc_collect_cycles() freed %d root buffers', $recollected));

\gc_enable();

experiment_note('the cyclic case is the long-running-worker trap: a worker that accumulates cycles keeps the memory until cycle collection runs, automatically or via gc_collect_cycles().');
experiment_context();