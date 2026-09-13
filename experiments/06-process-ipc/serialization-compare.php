#!/usr/bin/env php
<?php

declare(strict_types=1);

require \dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../run-helpers.php';

use App\Memory\ByteFormatter;

experiment_start('serialization: what a message costs before it reaches the socket');

/*
 * Framing moves bytes; serialization decides how many bytes there are. The
 * four formats below differ in what they carry besides the data: json and
 * serialize() describe the shape of every value inside the payload, csv and
 * the packed binary format assume both sides already agreed on it, and a raw
 * string carries nothing at all - it is the floor every other row is measured
 * against.
 */
$records = [];

for ($i = 0; $i < 1_000; $i++) {
    $records[] = [
        'id' => $i + 1,
        'name' => \sprintf('user-%d', $i),
        'email' => \sprintf('user%d@example.org', $i),
        'active' => ($i % 2) === 0,
        'score' => ($i * 37) % 1_000,
        'tags' => ['php', 'memory', 'ipc'],
    ];
}

/** @var array<string, array{callable(array<int, array<string, mixed>>): string, callable(string): mixed}> $formats */
$formats = [
    'json' => [
        static fn (array $rows): string => \json_encode($rows, JSON_THROW_ON_ERROR),
        static fn (string $wire): mixed => \json_decode($wire, true, 512, JSON_THROW_ON_ERROR),
    ],

    'serialize' => [
        static fn (array $rows): string => \serialize($rows),
        static fn (string $wire): mixed => \unserialize($wire, ['allowed_classes' => false]),
    ],

    'csv' => [
        static function (array $rows): string {
            $out = '';

            foreach ($rows as $row) {
                $out .= \implode(',', [
                    $row['id'],
                    $row['name'],
                    $row['email'],
                    (int) $row['active'],
                    $row['score'],
                    \implode('|', $row['tags']),
                ]) . "\n";
            }

            return $out;
        },
        static function (string $wire): array {
            $rows = [];

            foreach (\explode("\n", \rtrim($wire, "\n")) as $line) {
                [$id, $name, $email, $active, $score, $tags] = \explode(',', $line);

                $rows[] = [
                    'id' => (int) $id,
                    'name' => $name,
                    'email' => $email,
                    'active' => $active === '1',
                    'score' => (int) $score,
                    'tags' => \explode('|', $tags),
                ];
            }

            return $rows;
        },
    ],

    // Fixed layout, agreed out of band: [rows][id][name len][name]... Nothing
    // in the payload says what any field means, which is exactly why it is
    // the smallest and the most brittle of the four.
    'binary' => [
        static function (array $rows): string {
            $out = \pack('N', \count($rows));

            foreach ($rows as $row) {
                $out .= \pack('N', $row['id'])
                    . \pack('n', \strlen($row['name'])) . $row['name']
                    . \pack('n', \strlen($row['email'])) . $row['email']
                    . \pack('C', (int) $row['active'])
                    . \pack('n', $row['score'])
                    . \pack('C', \count($row['tags']));

                foreach ($row['tags'] as $tag) {
                    $out .= \pack('C', \strlen($tag)) . $tag;
                }
            }

            return $out;
        },
        static function (string $wire): array {
            $offset = 0;

            $take = static function (string $format, int $width) use ($wire, &$offset): int {
                /** @var array{1: int} $unpacked */
                $unpacked = \unpack($format, \substr($wire, $offset, $width));
                $offset += $width;

                return $unpacked[1];
            };

            $takeString = static function (int $length) use ($wire, &$offset): string {
                $value = \substr($wire, $offset, $length);
                $offset += $length;

                return $value;
            };

            $rows = [];
            $count = $take('N', 4);

            for ($i = 0; $i < $count; $i++) {
                $id = $take('N', 4);
                $name = $takeString($take('n', 2));
                $email = $takeString($take('n', 2));
                $active = $take('C', 1) === 1;
                $score = $take('n', 2);
                $tags = [];

                for ($t = $take('C', 1); $t > 0; $t--) {
                    $tags[] = $takeString($take('C', 1));
                }

                $rows[] = \compact('id', 'name', 'email', 'active', 'score', 'tags');
            }

            return $rows;
        },
    ],
];

\fwrite(STDOUT, \sprintf(
    "\n%-10s | %9s | %9s %9s %11s | %10s | %s\n",
    'format',
    'bytes',
    'encode',
    'decode',
    'round-trip',
    'peak alloc',
    'round-trips equal?',
));
\fwrite(STDOUT, \str_repeat('-', 88) . "\n");

foreach ($formats as $name => [$encode, $decode]) {
    \gc_collect_cycles();
    \memory_reset_peak_usage();
    $baseline = \memory_get_usage();

    $start = \hrtime(true);
    $wire = $encode($records);
    $encodeNs = \hrtime(true) - $start;

    $start = \hrtime(true);
    $decoded = $decode($wire);
    $decodeNs = \hrtime(true) - $start;

    \fwrite(STDOUT, \sprintf(
        "%-10s | %9s | %6.2f ms %6.2f ms %8.2f ms | %10s | %s\n",
        $name,
        ByteFormatter::format(\strlen($wire)),
        $encodeNs / 1e6,
        $decodeNs / 1e6,
        ($encodeNs + $decodeNs) / 1e6,
        ByteFormatter::format(\memory_get_peak_usage() - $baseline),
        $decoded === $records ? 'yes' : 'NO - lossy',
    ));

    unset($wire, $decoded);
}

/*
 * The floor. A payload that is already a string needs no encoder at all, and
 * its "round trip" is a copy - which is what every row above is paying extra
 * for in exchange for structure.
 */
$raw = \str_repeat('x', 100_000);
\gc_collect_cycles();
\memory_reset_peak_usage();
$baseline = \memory_get_usage();
$start = \hrtime(true);
$copy = (string) $raw;
$rawNs = \hrtime(true) - $start;

\fwrite(STDOUT, \sprintf(
    "%-10s | %9s | %6s    %6s    %8.2f ms | %10s | %s\n",
    'raw string',
    ByteFormatter::format(\strlen($raw)),
    'n/a',
    'n/a',
    $rawNs / 1e6,
    ByteFormatter::format(\memory_get_peak_usage() - $baseline),
    $copy === $raw ? 'yes' : 'NO - lossy',
));

experiment_note('csv and binary round-trip only because their decoders hard-code which column is an int and which is a bool; the payloads themselves say nothing about types, so the agreement lives in two files that have to be changed together.');
experiment_note('smallest on the wire is not fastest in PHP: the packed format halves json\'s bytes and is the slowest to decode, because unpacking field by field runs in userland while json_decode() runs one C call over the whole payload. Fewer bytes are worth paying for when the wire is the bottleneck - a network, a full socket buffer - and not when it is not.');
experiment_context();
