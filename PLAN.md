# php-memory-lab

# PHP Memory & Process Internals Lab

An educational laboratory for understanding how PHP applications interact with memory, processes, operating-system primitives, inter-process communication, memory-mapped files, and native code.

The project focuses on practical experiments, measurable behavior, and small isolated implementations rather than production-ready infrastructure.

---

## Table of Contents

- [Project Overview](#project-overview)
- [Project Goals](#project-goals)
- [Non-Goals](#non-goals)
- [Learning Outcomes](#learning-outcomes)
- [Technology Stack](#technology-stack)
- [Repository Structure](#repository-structure)
- [Development Principles](#development-principles)
- [Phase 0 — Project Setup](#phase-0--project-setup)
- [Phase 1 — Memory Measurement Basics](#phase-1--memory-measurement-basics)
- [Phase 2 — PHP Arrays, Strings and Garbage Collection](#phase-2--php-arrays-strings-and-garbage-collection)
- [Phase 3 — Processes and fork](#phase-3--processes-and-fork)
- [Phase 4 — Copy-on-Write Experiments](#phase-4--copy-on-write-experiments)
- [Phase 5 — Process IPC with Unix Sockets](#phase-5--process-ipc-with-unix-sockets)
- [Phase 6 — SysV Shared Memory and Semaphores](#phase-6--sysv-shared-memory-and-semaphores)
- [Phase 7 — Shared-Memory Ring Buffer](#phase-7--shared-memory-ring-buffer)
- [Phase 8 — mmap](#phase-8--mmap)
- [Phase 9 — FFI and Native Memory](#phase-9--ffi-and-native-memory)
- [Phase 10 — Benchmark Harness](#phase-10--benchmark-harness)
- [Phase 11 — Experiments CLI](#phase-11--experiments-cli)
- [Phase 12 — Documentation](#phase-12--documentation)
- [Testing Strategy](#testing-strategy)
- [Benchmarking Strategy](#benchmarking-strategy)
- [Safety Rules](#safety-rules)
- [Recommended Implementation Order](#recommended-implementation-order)
- [First MVP](#first-mvp)
- [Definition of Done](#definition-of-done)
- [Future Extensions](#future-extensions)
- [Final Project Direction](#final-project-direction)

---

## Project Overview

### Project Name

`php-memory-lab`

### Suggested GitHub Repository

`Researcher86/php-memory-lab`

### Short Description

Educational laboratory for PHP memory, processes, IPC, mmap, FFI, and operating-system internals.

### Full Description

`php-memory-lab` is a practical educational project that explores how PHP applications use memory and interact with Linux process and memory-management primitives.

The project contains small experiments, reusable measurement tools, benchmarks, and documentation covering:

- PHP memory allocation.
- PHP arrays and strings.
- Reference counting.
- Garbage collection.
- Process creation with `fork()`.
- Copy-on-Write.
- Unix socket IPC.
- SysV message queues.
- SysV shared memory.
- Semaphores.
- Shared-memory data structures.
- Ring buffers.
- Memory-mapped files.
- Native memory.
- PHP FFI.
- PHP-to-C interaction.
- Memory and process benchmarking.

The project is designed as a learning laboratory, not as a production framework.

---

## Project Goals

The main goal is to understand what happens inside PHP and Linux when an application:

- Allocates memory.
- Creates large arrays and strings.
- Uses garbage collection.
- Forks child processes.
- Modifies memory after `fork()`.
- Triggers Copy-on-Write.
- Communicates between processes.
- Uses shared memory.
- Uses memory-mapped files.
- Allocates native memory through FFI.
- Exchanges data between PHP and C.
- Uses different IPC mechanisms.
- Measures process memory and operating-system RSS.

The project should answer practical questions such as:

- How much memory does a PHP array really use?
- What is the difference between PHP memory usage and process RSS?
- How does `fork()` affect memory?
- When does Copy-on-Write allocate new physical pages?
- How expensive is modifying one element in a large array?
- How much memory is shared between parent and child processes?
- What is the difference between sockets, SysV IPC, shared memory, and `mmap()`?
- How much overhead does PHP add compared with native memory?
- What happens when PHP owns memory allocated by C?
- How can memory usage and process behavior be measured reliably?

---

## Non-Goals

This project is not intended to be:

- A production memory allocator.
- A production IPC framework.
- A complete shared-memory database.
- A replacement for Redis.
- A replacement for operating-system tools.
- A high-performance native runtime.
- A complete PHP extension.
- A fully lock-free concurrency framework.
- A production-ready storage engine.

The main priority is:

> Learning through small experiments, controlled changes, and measurable results.

---

## Learning Outcomes

After completing the project, the developer should understand:

### PHP Memory

- PHP zvals.
- Reference counting.
- Copy-on-write at the PHP-engine level.
- Hash tables.
- Packed arrays.
- Associative arrays.
- Memory arenas.
- Memory allocation and reuse.
- Garbage collection.
- Cyclic references.
- Difference between freeing an object and returning memory to the OS.

### Linux Processes

- Process IDs.
- Parent and child processes.
- `fork()`.
- Process memory inheritance.
- Process lifecycle.
- Waiting for children.
- Exit codes.
- Signals.
- Zombie processes.

### Linux Memory

- Virtual memory.
- Resident memory.
- RSS.
- PSS.
- Private memory.
- Shared memory.
- Anonymous memory.
- File-backed memory.
- Dirty pages.
- Page faults.
- Copy-on-Write.

### IPC

- Unix sockets.
- Message framing.
- Partial reads.
- Partial writes.
- SysV message queues.
- SysV shared memory.
- Semaphores.
- Shared-memory synchronization.
- Race conditions.
- Backpressure.

### Native Integration

- Native pointers.
- Native allocation.
- Native deallocation.
- Memory ownership.
- Buffer boundaries.
- FFI.
- C ABI.
- Memory safety risks.

---

## Technology Stack

### Required

- PHP 8.4+
- Composer
- PHPUnit
- PHPStan
- Docker
- Linux environment

### PHP Extensions

The project may use:

- `pcntl`
- `posix`
- `sockets`
- `sysvmsg`
- `sysvsem`
- `sysvshm`
- `ffi`

### Linux Tools

Recommended tools:

- `procps`
- `ps`
- `top`
- `htop`
- `pmap`
- `smem`
- `strace`
- `time`
- `perf`
- `vmstat`
- `free`
- `lsof`

Some tools may require additional permissions or may not be available in restricted containers.

---

## Repository Structure

```text
php-memory-lab/
├── README.md
├── LICENSE
├── composer.json
├── composer.lock
├── Makefile
├── Dockerfile
├── docker-compose.yml
├── phpunit.xml
├── phpstan.neon
│
├── bin/
│   ├── experiment
│   └── benchmark
│
├── src/
│   ├── Memory/
│   │   ├── MemorySnapshot.php
│   │   ├── MemoryReporter.php
│   │   ├── ProcStatusReader.php
│   │   └── SmapsRollupReader.php
│   │
│   ├── Process/
│   │   ├── ProcessInfo.php
│   │   ├── ForkResult.php
│   │   └── ProcessRunner.php
│   │
│   ├── Ipc/
│   │   ├── SocketChannel.php
│   │   ├── MessageFramer.php
│   │   ├── SysvMessageQueue.php
│   │   ├── SharedMemorySegment.php
│   │   ├── Semaphore.php
│   │   └── RingBuffer.php
│   │
│   ├── Native/
│   │   ├── FfiBuffer.php
│   │   ├── NativeAllocator.php
│   │   ├── NativeLibrary.php
│   │   └── MappedFile.php
│   │
│   └── Benchmark/
│       ├── BenchmarkRunner.php
│       ├── BenchmarkResult.php
│       └── ResultWriter.php
│
├── experiments/
│   ├── 01-memory-basics/
│   ├── 02-arrays-and-strings/
│   ├── 03-garbage-collection/
│   ├── 04-fork/
│   ├── 05-copy-on-write/
│   ├── 06-process-ipc/
│   ├── 07-shared-memory/
│   ├── 08-ring-buffer/
│   ├── 09-mmap/
│   └── 10-ffi/
│
├── tests/
│   ├── Memory/
│   ├── Process/
│   ├── Ipc/
│   └── Native/
│
├── benchmarks/
│   ├── memory-allocation.php
│   ├── array-memory.php
│   ├── cow.php
│   ├── ipc.php
│   └── ffi.php
│
├── docs/
│   ├── memory-model.md
│   ├── php-memory-vs-rss.md
│   ├── fork-and-cow.md
│   ├── ipc-comparison.md
│   ├── shared-memory.md
│   ├── mmap.md
│   └── ffi-memory.md
│
└── results/
    └── .gitkeep
```

---

## Development Principles

### 1. Small Experiments

Every new concept should first be implemented as a small isolated experiment.

Avoid combining several complex concepts in the first version.

Recommended sequence:

1. Measure RSS.
2. Create a child process.
3. Share memory through inheritance.
4. Modify inherited memory.
5. Implement IPC.

### 2. Measure Before Optimizing

Do not assume that one mechanism is faster or more memory-efficient.

Measure:

- Execution time.
- Memory usage.
- RSS.
- PSS.
- Private memory.
- Throughput.
- Latency.
- Number of processes.
- Payload size.

### 3. Explain Every Experiment

Every experiment should document:

- What is being tested.
- Why the experiment exists.
- What is expected.
- What was measured.
- Why the result happened.
- What limitations exist.

### 4. Separate PHP Memory from OS Memory

Always distinguish:

- PHP memory usage.
- Process RSS.
- Virtual memory.
- Shared memory.
- Private memory.
- PSS.

### 5. Keep the First Version Simple

Do not start with:

- Lock-free algorithms.
- Multiple producers and consumers.
- Complex memory allocators.
- Crash recovery.
- Zero-copy variable-length structures.
- Production-grade APIs.

Start with a simple correct implementation.

### 6. Preserve Reproducibility

Every benchmark should record:

- PHP version.
- OS.
- CPU.
- Container limits.
- PHP configuration.
- JIT status.
- OPcache status.
- Number of iterations.
- Payload size.
- Process count.

---

## Phase 0 — Project Setup

### Objectives

Prepare a reproducible environment for all experiments.

### Tasks

#### 0.1 Create the Repository

Create:

```text
Researcher86/php-memory-lab
```

Initialize the project:

```bash
mkdir php-memory-lab
cd php-memory-lab

git init
composer init
```

#### 0.2 Initialize Composer

Create `composer.json`:

```json
{
  "name": "researcher86/php-memory-lab",
  "description": "Educational laboratory for PHP memory, processes, IPC, mmap and FFI",
  "type": "project",
  "license": "MIT",
  "require": {
    "php": "^8.4"
  },
  "require-dev": {
    "phpunit/phpunit": "^11.0",
    "phpstan/phpstan": "^2.0",
    "friendsofphp/php-cs-fixer": "^3.0"
  },
  "autoload": {
    "psr-4": {
      "MemoryLab\\": "src/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "MemoryLab\\Tests\\": "tests/"
    }
  },
  "scripts": {
    "test": "phpunit",
    "analyse": "phpstan analyse",
    "format": "php-cs-fixer fix --diff"
  }
}
```

#### 0.3 Prepare the Docker Environment

Create `Dockerfile`:

```dockerfile
FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y \
        libffi-dev \
        procps \
        strace \
        linux-perf \
        time \
    && docker-php-ext-install \
        pcntl \
        sockets \
        sysvmsg \
        sysvsem \
        sysvshm \
    && rm -rf /var/lib/apt/lists/*

RUN printf "ffi.enable=true\n" \
    > /usr/local/etc/php/conf.d/ffi.ini

WORKDIR /app

COPY composer.json composer.lock ./

RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php composer-setup.php \
        --install-dir=/usr/local/bin \
        --filename=composer \
    && rm composer-setup.php

RUN composer install \
    --no-interaction \
    --prefer-dist

COPY . .
```

#### 0.4 Create Docker Compose Configuration

Create `docker-compose.yml`:

```yaml
services:
  php:
    build:
      context: .
    working_dir: /app
    volumes:
      - ./:/app
    stdin_open: true
    tty: true
```

#### 0.5 Add a Makefile

Create `Makefile`:

```makefile
.PHONY: install test analyse format experiment benchmark shell

install:
	composer install

test:
	composer test

analyse:
	composer analyse

format:
	composer format

experiment:
	php bin/experiment

benchmark:
	php bin/benchmark

shell:
	docker compose run --rm php bash
```

#### 0.6 Add PHPUnit Configuration

Create `phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>

<phpunit
    bootstrap="vendor/autoload.php"
    colors="true"
    cacheDirectory=".phpunit.cache"
>
    <testsuites>
        <testsuite name="default">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

#### 0.7 Add PHPStan Configuration

Create `phpstan.neon`:

```neon
parameters:
    level: 8
    paths:
        - src
        - tests
```

#### 0.8 Initial Commit

```bash
git add .
git commit -m "Initialize php-memory-lab project"
```

---

## Phase 1 — Memory Measurement Basics

### Objectives

Build a reliable memory measurement layer.

The first important distinction is:

- PHP memory usage.
- Process RSS.
- Virtual memory.
- Resident shared memory.
- Private memory.
- PSS.

### 1.1 Implement `MemorySnapshot`

Create:

```text
src/Memory/MemorySnapshot.php
```

Suggested implementation:

```php
<?php

declare(strict_types=1);

namespace MemoryLab\Memory;

final readonly class MemorySnapshot
{
    public function __construct(
        public int $phpUsage,
        public int $phpPeakUsage,
        public int $phpRealUsage,
        public int $phpRealPeakUsage,
        public ?int $rss = null,
        public ?int $virtualMemory = null,
        public ?int $sharedMemory = null,
        public ?int $privateMemory = null,
        public ?int $pss = null,
    ) {
    }
}
```

### 1.2 Implement `/proc/self/status` Reader

Create:

```text
src/Memory/ProcStatusReader.php
```

Parse fields such as:

- `VmPeak`
- `VmSize`
- `VmRSS`
- `RssAnon`
- `RssFile`
- `RssShmem`
- `VmData`
- `VmStk`
- `VmExe`
- `VmLib`
- `VmPTE`
- `VmSwap`

Suggested API:

```php
<?php

declare(strict_types=1);

namespace MemoryLab\Memory;

final class ProcStatusReader
{
    /**
     * @return array<string, int|string>
     */
    public function read(int $pid = 0): array
    {
        $actualPid = $pid > 0 ? $pid : getmypid();

        if ($actualPid === false) {
            throw new \RuntimeException('Unable to determine process ID');
        }

        $path = sprintf('/proc/%d/status', $actualPid);

        if (!is_readable($path)) {
            throw new \RuntimeException(
                sprintf('Unable to read %s', $path),
            );
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new \RuntimeException(
                sprintf('Unable to read %s', $path),
            );
        }

        $result = [];

        foreach (explode("\n", $content) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);

            $value = trim($value);

            if (preg_match('/^(\d+)\s+kB$/', $value, $matches)) {
                $result[$key] = (int) $matches[1] * 1024;
                continue;
            }

            if (is_numeric($value)) {
                $result[$key] = (int) $value;
                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
```

### 1.3 Implement `/proc/self/smaps_rollup` Reader

Create:

```text
src/Memory/SmapsRollupReader.php
```

Parse:

- `Rss`
- `Pss`
- `Pss_Anon`
- `Pss_File`
- `Pss_Shmem`
- `Private_Clean`
- `Private_Dirty`
- `Shared_Clean`
- `Shared_Dirty`
- `Anonymous`
- `AnonHugePages`
- `Swap`

This data will be especially useful for Copy-on-Write experiments.

### 1.4 Implement `MemoryReporter`

Create:

```text
src/Memory/MemoryReporter.php
```

Responsibilities:

- Read PHP memory statistics.
- Read Linux process memory statistics.
- Return a `MemorySnapshot`.
- Calculate differences.
- Support a selected PID.

Suggested API:

```php
interface MemoryReporterInterface
{
    public function snapshot(?int $pid = null): MemorySnapshot;

    /**
     * @return array<string, int>
     */
    public function diff(
        MemorySnapshot $before,
        MemorySnapshot $after,
    ): array;
}
```

### 1.5 Basic Memory Experiment

Create:

```text
experiments/01-memory-basics/empty.php
```

Test:

- Empty PHP process.
- Memory before allocation.
- Memory after allocation.
- Memory after cleanup.

Example:

```php
<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MemoryLab\Memory\MemoryReporter;

$reporter = new MemoryReporter();

$before = $reporter->snapshot();

$data = range(1, 1_000_000);

$after = $reporter->snapshot();

var_dump($reporter->diff($before, $after));

unset($data);

$afterCleanup = $reporter->snapshot();

var_dump($reporter->diff($after, $afterCleanup));
```

### 1.6 Expected Output

Each experiment should print information similar to:

```text
Experiment: large-array
PID: 1234

Before:
  PHP usage: 2.1 MB
  RSS: 18.4 MB
  Private memory: 12.7 MB

After:
  PHP usage: 34.2 MB
  RSS: 51.8 MB
  Private memory: 46.1 MB

Delta:
  PHP usage: +32.1 MB
  RSS: +33.4 MB
  Private memory: +33.4 MB
```

The exact values depend on:

- PHP version.
- Operating system.
- Allocator.
- Architecture.
- Container limits.
- PHP configuration.
- Existing process state.

---

## Phase 2 — PHP Arrays, Strings and Garbage Collection

### Objectives

Understand the memory overhead of common PHP data structures.

### 2.1 String Experiments

Measure:

- Empty string.
- Short string.
- 1 KB string.
- 1 MB string.
- 10 MB string.
- Repeated strings.
- String concatenation.
- String copying.
- Substrings.
- String modification.

Example:

```php
$value = str_repeat('A', 10_000_000);
```

Compare:

```php
$copy = $value;
```

with:

```php
$value[0] = 'B';
```

Questions:

- When is the string copied?
- Is the copy eager or lazy?
- How does PHP-level Copy-on-Write work?
- Does RSS change immediately?
- Does the allocator retain freed memory?

### 2.2 Packed Array Experiments

Compare:

```php
$data = range(1, 100_000);
```

with:

```php
$data = [];

for ($i = 0; $i < 100_000; $i++) {
    $data[] = $i;
}
```

Measure:

- Allocation time.
- PHP memory usage.
- RSS.
- Private memory.
- Peak memory.
- Cleanup behavior.

### 2.3 Associative Array Experiments

Test:

```php
$data = [];

for ($i = 0; $i < 100_000; $i++) {
    $data['key_' . $i] = $i;
}
```

Compare:

- Numeric keys.
- String keys.
- Sequential keys.
- Sparse keys.
- Long keys.
- Short keys.

### 2.4 Sparse Array Experiments

Compare:

```php
$data[0] = 1;
$data[1] = 2;
$data[2] = 3;
```

with:

```php
$data[1_000_000] = 1;
```

Investigate:

- Hash-table behavior.
- Memory overhead.
- Packed-array conversion.
- Sparse-array memory usage.

### 2.5 Nested Arrays

Test:

```php
$data = [];

for ($i = 0; $i < 10_000; $i++) {
    $data[] = [
        'id' => $i,
        'name' => 'name_' . $i,
        'active' => true,
    ];
}
```

Measure:

- Nested array overhead.
- String duplication.
- Shared values.
- Number of zvals.
- Memory growth.

### 2.6 Object Experiments

Compare:

- Empty object.
- Object with scalar properties.
- Object with string properties.
- Object with nested objects.
- Large number of objects.
- DTOs.
- Arrays of objects.

Example:

```php
final class User
{
    public function __construct(
        public int $id,
        public string $name,
        public bool $active,
    ) {
    }
}
```

### 2.7 Garbage Collection

Investigate:

- Reference counting.
- Cyclic references.
- `unset()`.
- `gc_collect_cycles()`.
- `gc_status()`.
- Memory reuse.
- Memory returned to the operating system.

Example:

```php
$a = new stdClass();
$b = new stdClass();

$a->peer = $b;
$b->peer = $a;

unset($a, $b);

gc_collect_cycles();
```

Document the difference between:

```text
Object becomes unreachable
        ↓
PHP frees the object
        ↓
PHP releases memory to its allocator
        ↓
Allocator returns memory to the OS
        ↓
RSS decreases
```

These events do not necessarily happen at the same time.

---

## Phase 3 — Processes and `fork()`

### Objectives

Understand process creation and memory inheritance.

### 3.1 Basic Fork

Create a simple experiment:

```php
<?php

declare(strict_types=1);

$pid = pcntl_fork();

if ($pid === -1) {
    throw new RuntimeException('Unable to fork');
}

if ($pid === 0) {
    echo 'Child PID: ' . getmypid() . PHP_EOL;
    exit(0);
}

echo 'Parent PID: ' . getmypid() . PHP_EOL;

pcntl_waitpid($pid, $status);
```

Measure:

- Parent PID.
- Child PID.
- Parent RSS.
- Child RSS.
- Process lifetime.
- Fork duration.
- Exit status.

### 3.2 Fork with Allocated Memory

Steps:

1. Start a PHP process.
2. Allocate a large array.
3. Measure memory.
4. Fork.
5. Measure parent and child memory.
6. Keep the child alive.
7. Inspect both processes.
8. Exit the child.
9. Wait in the parent.

Example:

```php
$data = range(1, 1_000_000);

$beforeFork = $reporter->snapshot();

$pid = pcntl_fork();

if ($pid === -1) {
    throw new RuntimeException('Unable to fork');
}

if ($pid === 0) {
    $childSnapshot = $reporter->snapshot();

    sleep(5);

    exit(0);
}

$parentSnapshot = $reporter->snapshot();

pcntl_waitpid($pid, $status);
```

### 3.3 Multiple Children

Test:

- One child.
- Two children.
- Four children.
- Eight children.
- Sixteen children.

Measure:

- Fork time.
- Total RSS.
- Parent private memory.
- Child private memory.
- Shared memory.
- PSS.
- Memory after children modify data.

### 3.4 Process Lifecycle

Investigate:

- Child exits normally.
- Child exits with a non-zero code.
- Parent waits immediately.
- Parent delays waiting.
- Zombie processes.
- `pcntl_waitpid()`.
- Signals.
- Child termination.

Document:

```text
fork()
  ├── Parent continues
  └── Child continues
        └── Child exits
              └── Parent waits
```

---

## Phase 4 — Copy-on-Write Experiments

### Objectives

Understand how Linux shares memory pages after `fork()` and when pages become private.

### 4.1 Read-Only Child

Steps:

1. Allocate a large structure in the parent.
2. Fork.
3. Child only reads the structure.
4. Measure memory.
5. Compare parent and child PSS.

Question:

> Does reading inherited memory create private pages?

### 4.2 Modify One Element

Steps:

1. Allocate a large array.
2. Fork.
3. Modify one element in the child.
4. Measure RSS.
5. Measure private dirty memory.
6. Compare before and after.

Example:

```php
$data = range(1, 1_000_000);

$pid = pcntl_fork();

if ($pid === 0) {
    $data[0] = 999;

    sleep(5);

    exit(0);
}

pcntl_waitpid($pid, $status);
```

### 4.3 Modify Many Elements

Compare:

```php
$data[0] = 1;
```

with:

```php
for ($i = 0; $i < 100_000; $i++) {
    $data[$i] = $i * 2;
}
```

Measure:

- Number of modified elements.
- RSS delta.
- PSS delta.
- Private dirty memory.
- Execution time.
- Number of page faults if available.

### 4.4 Rewrite the Entire Array

Compare:

```php
foreach ($data as $key => $value) {
    $data[$key] = $value + 1;
}
```

with:

```php
$data = range(1, 1_000_000);
```

Document why modifying inherited memory and replacing a variable can have different memory behavior.

### 4.5 Multiple Children with Different Memory Regions

Create:

- One parent.
- Four children.
- Each child modifies a different portion of memory.

Example distribution:

```text
Child 1: elements 0–249,999
Child 2: elements 250,000–499,999
Child 3: elements 500,000–749,999
Child 4: elements 750,000–999,999
```

Measure:

- Parent private memory.
- Child private memory.
- Shared memory.
- PSS.
- Total physical memory estimate.

### 4.6 Copy-on-Write Documentation

Create:

```text
docs/fork-and-cow.md
```

Explain:

- Virtual address spaces.
- Page tables.
- Shared physical pages.
- Page faults.
- Private dirty pages.
- Why RSS can be misleading.
- Why PSS is useful.
- Why PHP-level memory functions cannot show the complete picture.
- Difference between PHP-engine Copy-on-Write and Linux page-level Copy-on-Write.

---

## Phase 5 — Process IPC with Unix Sockets

### Objectives

Build a baseline IPC implementation before experimenting with shared memory.

### 5.1 Unix Socket Pair

Use:

```php
socket_create_pair(
    AF_UNIX,
    SOCK_STREAM,
    0,
    $sockets,
);
```

Create:

```text
src/Ipc/SocketChannel.php
```

Suggested API:

```php
final class SocketChannel
{
    public function send(string $payload): void
    {
    }

    public function receive(): string
    {
    }
}
```

### 5.2 Message Framing

Implement length-prefixed messages:

```text
[4-byte payload length][payload bytes]
```

The implementation must handle:

- Partial reads.
- Partial writes.
- Multiple messages in one read.
- One message split across multiple reads.
- Empty payloads.
- Large payloads.
- Invalid lengths.
- EOF.
- Broken pipes.
- Unexpected child termination.

### 5.3 IPC Experiments

Measure:

- Round-trip latency.
- Throughput.
- Message size.
- Number of messages.
- Number of processes.
- Blocking sockets.
- Non-blocking sockets.
- One-way communication.
- Request-response communication.

Payload sizes:

```text
0 bytes
16 bytes
128 bytes
1 KB
4 KB
64 KB
1 MB
10 MB
```

### 5.4 Serialization Comparison

Compare:

- JSON.
- PHP `serialize()`.
- Custom binary format.
- Raw strings.

Measure:

- Encoding time.
- Decoding time.
- Payload size.
- Round-trip latency.
- Memory allocation.
- CPU usage.

### 5.5 Backpressure Experiment

Create a producer that is faster than the consumer.

Measure:

- Socket buffer growth.
- Blocking behavior.
- Producer waiting time.
- Consumer throughput.
- Memory growth.
- Message loss behavior if the producer is terminated.

---

## Phase 6 — SysV Shared Memory and Semaphores

### Objectives

Understand shared memory between independent processes.

### 6.1 Shared Memory Segment

Use:

```php
shm_attach(
    $key,
    $size,
    0666,
);
```

Create:

```text
src/Ipc/SharedMemorySegment.php
```

Suggested API:

```php
final class SharedMemorySegment
{
    public function put(string $key, mixed $value): void
    {
    }

    public function get(string $key): mixed
    {
    }

    public function remove(string $key): void
    {
    }

    public function detach(): void
    {
    }
}
```

Document that PHP SysV shared memory serializes values and is not equivalent to a raw shared byte buffer.

### 6.2 Semaphores

Use:

```php
sem_get($key);
sem_acquire($semaphore);
sem_release($semaphore);
```

Create a protected counter:

1. Parent creates shared memory.
2. Parent creates a semaphore.
3. Parent forks children.
4. Each child increments a shared counter.
5. Semaphore protects the critical section.
6. Parent waits for all children.
7. Final counter is verified.

### 6.3 Race Condition Experiment

Run the same counter without synchronization.

Compare:

```text
Expected result
Actual result
Number of lost updates
```

Repeat with:

- One child.
- Two children.
- Four children.
- Eight children.
- Sixteen children.

### 6.4 Shared Memory Limitations

Document:

- Serialization overhead.
- Segment size.
- Synchronization requirements.
- Cleanup problems.
- Stale segments.
- Process crashes.
- ABI and layout concerns.
- Difference from POSIX shared memory.
- Difference from `mmap()`.
- Difference from raw shared memory.

---

## Phase 7 — Shared-Memory Ring Buffer

### Objectives

Implement a simple fixed-size shared-memory ring buffer.

This is an educational project, not a production queue.

### 7.1 Initial Constraints

Start with:

- One producer.
- One consumer.
- Fixed-size messages.
- Fixed-size capacity.
- Semaphore-based synchronization.
- Blocking or polling mode.

Avoid initially:

- Multiple producers.
- Multiple consumers.
- Lock-free algorithms.
- Dynamic resizing.
- Crash recovery.
- Zero-copy variable-size messages.

### 7.2 Ring Buffer Layout

The ring buffer contains:

```text
Header
 ├── magic
 ├── version
 ├── capacity
 ├── slot size
 ├── read position
 ├── write position
 ├── item count
 └── state

Data area
 └── fixed-size slots
```

Example slot:

```text
[message length][message payload]
```

### 7.3 Required Operations

```php
interface RingBufferInterface
{
    public function push(string $payload): bool;

    public function pop(): ?string;

    public function isEmpty(): bool;

    public function isFull(): bool;

    public function size(): int;

    public function capacity(): int;
}
```

### 7.4 Ring Buffer Experiments

Measure:

- Push latency.
- Pop latency.
- Throughput.
- Full-buffer behavior.
- Empty-buffer behavior.
- Message sizes.
- Producer/consumer speed mismatch.
- Semaphore contention.
- Polling interval.
- Blocking behavior.

### 7.5 Failure Scenarios

Test:

- Producer exits unexpectedly.
- Consumer exits unexpectedly.
- Process crashes while holding a lock.
- Buffer becomes full.
- Buffer becomes empty.
- Invalid header.
- Invalid message length.
- Corrupted slot.

Document which failures are handled and which are intentionally unsupported.

---

## Phase 8 — mmap

### Objectives

Understand memory-mapped files and their relationship to virtual memory.

### 8.1 Basic Concepts

Document:

- File-backed memory.
- Anonymous mappings.
- Private mappings.
- Shared mappings.
- Page faults.
- Lazy loading.
- Dirty pages.
- Persistence.
- Mapping size.
- File truncation requirements.
- `msync()`.
- Unmapping.

### 8.2 Implementation Options

Possible approaches:

1. Use FFI to call libc.
2. Create a small C helper library.
3. Use a PHP extension.
4. Use an external helper process.

For the first version, a small C helper accessed through FFI may be easiest to understand.

### 8.3 Suggested API

```php
final class MappedFile
{
    public function map(string $path, int $size): void
    {
    }

    public function read(int $offset, int $length): string
    {
    }

    public function write(int $offset, string $data): void
    {
    }

    public function flush(): void
    {
    }

    public function unmap(): void
    {
    }
}
```

### 8.4 mmap Experiments

Measure:

- Mapping a small file.
- Mapping a large file.
- Reading sequentially.
- Reading randomly.
- Writing sequentially.
- Writing randomly.
- Calling `msync()`.
- Mapping the same file in two processes.
- Shared mapping.
- Private mapping.
- File growth.
- File truncation.
- Page faults.

### 8.5 mmap Failure Scenarios

Test:

- Mapping a file that is too small.
- Reading outside the mapping.
- Writing outside the mapping.
- Unmapping twice.
- Mapping a deleted file.
- File truncation while mapped.
- Process crash before flushing.

Unsafe memory experiments should be isolated inside disposable containers.

---

## Phase 9 — FFI and Native Memory

### Objectives

Understand memory allocated outside the PHP engine.

### 9.1 Native Allocation

Use FFI to call:

```c
void *malloc(size_t size);
void free(void *ptr);
```

Create a wrapper around native memory.

### 9.2 Native Buffer

Implement a conceptual API:

```php
final class FfiBuffer
{
    public function __construct(
        private readonly int $size,
    ) {
    }

    public function write(int $offset, string $data): void
    {
    }

    public function read(int $offset, int $length): string
    {
    }

    public function free(): void
    {
    }
}
```

### 9.3 Memory Ownership Experiments

Investigate:

- Native allocation.
- Native deallocation.
- Double free.
- Use-after-free.
- Buffer overflow.
- PHP object lifetime.
- FFI object lifetime.
- Native memory not visible to `memory_get_usage()`.
- Native memory visible in RSS.
- Explicit cleanup.
- Destructor-based cleanup.

Unsafe experiments should be isolated and run only inside disposable containers.

### 9.4 Compare PHP and Native Buffers

Compare:

```text
PHP string
PHP array
FFI C buffer
mmap-backed buffer
```

Measure:

- Allocation time.
- Memory usage.
- Access time.
- Copying cost.
- Serialization cost.
- Cleanup behavior.
- RSS behavior.

### 9.5 FFI Safety Documentation

Create:

```text
docs/ffi-memory.md
```

Explain:

- Native pointers.
- Ownership.
- Allocation.
- Deallocation.
- Buffer boundaries.
- Lifetime.
- Undefined behavior.
- ABI compatibility.
- Why FFI requires extra care.
- Why native memory should not be trusted without validation.

---

## Phase 10 — Benchmark Harness

### Objectives

Create a reusable benchmark system for all experiments.

### 10.1 Benchmark API

```php
final class BenchmarkRunner
{
    public function run(
        string $name,
        callable $callback,
        int $iterations = 1,
    ): BenchmarkResult {
    }
}
```

### 10.2 Benchmark Result

```php
final readonly class BenchmarkResult
{
    public function __construct(
        public string $name,
        public int $iterations,
        public float $elapsedSeconds,
        public float $operationsPerSecond,
        public ?int $memoryDelta = null,
        public ?int $rssDelta = null,
    ) {
    }
}
```

### 10.3 Output Formats

Support:

- Human-readable text.
- JSON.
- CSV.

Example:

```json
{
  "name": "socket-roundtrip-1kb",
  "iterations": 100000,
  "elapsed_seconds": 1.82,
  "operations_per_second": 54945.05,
  "memory_delta": 8192,
  "rss_delta": 1048576
}
```

### 10.4 Benchmark Rules

Each benchmark should document:

- PHP version.
- Operating system.
- CPU.
- Container limits.
- Number of iterations.
- Warm-up iterations.
- Payload size.
- Process count.
- Synchronization method.
- Whether JIT is enabled.
- Whether OPcache is enabled.
- Whether results are stable across multiple runs.

Avoid presenting one measurement as a universal truth.

### 10.5 Benchmark Repetitions

Use:

- Warm-up runs.
- Multiple repetitions.
- Minimum value.
- Maximum value.
- Average value.
- Median.
- Optional percentiles.

Example output:

```text
Benchmark: socket-roundtrip-1kb
Iterations: 100000
Repetitions: 5

Min:    1.71 s
Max:    1.92 s
Mean:   1.81 s
Median: 1.82 s

Throughput:
  55,000 operations/sec
```

---

## Phase 11 — Experiments CLI

### Objectives

Provide a consistent way to run experiments.

### 11.1 Example Commands

```bash
php bin/experiment memory:empty
php bin/experiment memory:array
php bin/experiment memory:string
php bin/experiment memory:gc

php bin/experiment process:fork
php bin/experiment process:multiple-forks

php bin/experiment cow:readonly
php bin/experiment cow:single-write
php bin/experiment cow:many-writes
php bin/experiment cow:multiple-children

php bin/experiment ipc:socket
php bin/experiment ipc:sysv
php bin/experiment ipc:shared-memory

php bin/experiment mmap:read
php bin/experiment mmap:write

php bin/experiment ffi:allocation
php bin/experiment ffi:buffer
```

### 11.2 Common Options

```bash
php bin/experiment cow:many-writes \
    --elements=1000000 \
    --children=4 \
    --sleep=5 \
    --format=json
```

Possible options:

```text
--size
--elements
--children
--iterations
--payload-size
--duration
--sleep
--format
--output
--verbose
```

### 11.3 Experiment Interface

Consider a common interface:

```php
interface ExperimentInterface
{
    public function name(): string;

    public function description(): string;

    /**
     * @param array<string, mixed> $options
     */
    public function run(array $options = []): ExperimentResult;
}
```

Each experiment should:

- Validate its options.
- Print or return structured results.
- Avoid hidden global state.
- Clean up child processes.
- Clean up IPC resources.
- Explain unsupported platforms.

---

## Phase 12 — Documentation

### Required Documents

#### `docs/memory-model.md`

Explain:

- PHP zvals.
- Reference counting.
- PHP-level Copy-on-Write.
- Hash tables.
- Packed arrays.
- Associative arrays.
- Memory arenas.
- Allocator behavior.
- Garbage collection.
- Object memory overhead.
- String memory behavior.

#### `docs/php-memory-vs-rss.md`

Explain:

- `memory_get_usage()`.
- `memory_get_peak_usage()`.
- RSS.
- Virtual memory.
- Shared memory.
- Private memory.
- PSS.
- Why measurements differ.
- Why freed PHP memory may remain in RSS.
- Why RSS may double-count shared pages across processes.

#### `docs/fork-and-cow.md`

Explain:

- `fork()`.
- Virtual address spaces.
- Page tables.
- Shared physical pages.
- Page faults.
- Private dirty pages.
- Parent/child memory accounting.
- PHP-level Copy-on-Write versus Linux-level Copy-on-Write.

#### `docs/ipc-comparison.md`

Compare:

| Mechanism | Main purpose | Copying | Synchronization | Complexity |
|---|---|---:|---:|---:|
| Unix socket | Message exchange | Usually yes | Stream semantics | Low |
| SysV message queue | Kernel-managed messages | Yes | Kernel-managed | Low/medium |
| SysV shared memory | Shared data | Depends on representation | External | Medium |
| Semaphore | Synchronization | No | Explicit | Low |
| `mmap()` | Shared/file-backed memory | Depends on access | External | High |
| FFI | Native integration | Depends on API | Depends on API | High |

#### `docs/shared-memory.md`

Explain:

- Shared addressability.
- Synchronization.
- Memory visibility.
- Race conditions.
- Atomicity.
- Cleanup.
- Crash consistency.
- Segment lifecycle.
- Stale resources.

#### `docs/mmap.md`

Explain:

- File-backed mappings.
- Anonymous mappings.
- `MAP_SHARED`.
- `MAP_PRIVATE`.
- `msync()`.
- Page faults.
- Persistence.
- Mapping lifetime.
- File truncation.

#### `docs/ffi-memory.md`

Explain:

- Native pointers.
- Ownership.
- Allocation.
- Deallocation.
- Buffer boundaries.
- Lifetime.
- Undefined behavior.
- ABI compatibility.
- Native memory accounting.

---

## Testing Strategy

### Unit Tests

Test:

- Memory snapshot creation.
- `/proc` parsing.
- `/proc` field conversion.
- Missing `/proc` files.
- Invalid data.
- Memory diff calculations.
- Message framing.
- Partial reads.
- Partial writes.
- Invalid message lengths.
- Ring buffer state transitions.
- Semaphore lifecycle.
- Native buffer bounds.

### Integration Tests

Test:

- Parent/child process communication.
- Unix socket pair.
- SysV shared memory.
- SysV semaphores.
- Ring buffer producer/consumer.
- FFI allocation and cleanup.
- mmap read/write behavior.

### Platform Tests

Some tests should be skipped when:

- Running on non-Linux.
- `pcntl` is unavailable.
- SysV extensions are unavailable.
- FFI is disabled.
- `/proc` is unavailable.
- Required permissions are missing.

Example:

```php
if (!extension_loaded('pcntl')) {
    self::markTestSkipped('pcntl extension is required');
}
```

---

## Benchmarking Strategy

### Always Record Environment

Record:

- PHP version.
- OS.
- CPU architecture.
- CPU model.
- Container limits.
- Memory limit.
- JIT status.
- OPcache status.
- Allocator.
- Kernel version.

### Benchmark Categories

#### Memory Allocation

- Strings.
- Arrays.
- Objects.
- Nested structures.
- Native buffers.
- mmap regions.

#### Process Creation

- One `fork()`.
- Multiple forks.
- Fork with no allocated memory.
- Fork with large memory.
- Fork after warm-up.

#### Copy-on-Write

- Read-only access.
- One write.
- Many writes.
- Full rewrite.
- Multiple children.

#### IPC

- Unix socket.
- SysV message queue.
- SysV shared memory.
- Ring buffer.
- Different payload sizes.

#### Native Memory

- PHP string.
- FFI buffer.
- mmap buffer.
- Native allocation.
- Copying.
- Reading.
- Writing.

---

## Safety Rules

### Run Unsafe Experiments in Containers

Unsafe experiments may include:

- Double free.
- Use-after-free.
- Buffer overflow.
- Invalid pointer access.
- Invalid memory mapping.
- Unmapping invalid memory.
- Corrupted shared-memory headers.

These experiments must run only inside disposable containers.

### Never Run Unsafe Experiments on Production

Do not run native-memory corruption experiments:

- On production servers.
- Against production databases.
- In shared development environments.
- With sensitive data.
- Without resource limits.

### Always Validate Buffer Boundaries

For native buffers:

- Validate offsets.
- Validate lengths.
- Check integer overflow.
- Check allocation size.
- Reject negative values.
- Reject values larger than the buffer.
- Explicitly release resources.

### Always Clean Up IPC Resources

Clean up:

- Child processes.
- Semaphores.
- Shared-memory segments.
- Message queues.
- Temporary files.
- mmap mappings.
- Native allocations.

---

## Recommended Implementation Order

### Milestone 1 — Memory Reporter

Implement:

- `MemorySnapshot`.
- `MemoryReporter`.
- `/proc/self/status` reader.
- Basic memory experiments.
- JSON output.
- README documentation.

Deliverables:

```text
src/Memory/
experiments/01-memory-basics/
tests/Memory/
docs/php-memory-vs-rss.md
```

### Milestone 2 — Arrays, Strings and GC

Implement experiments for:

- Strings.
- Packed arrays.
- Associative arrays.
- Objects.
- Cyclic references.
- `unset()`.
- Garbage collection.

Deliverables:

```text
experiments/02-arrays-and-strings/
experiments/03-garbage-collection/
docs/memory-model.md
```

### Milestone 3 — Fork and Copy-on-Write

Implement:

- Basic fork.
- Fork with allocated memory.
- Read-only child.
- Single write.
- Many writes.
- Multiple children.
- Parent/child memory reporting.

Deliverables:

```text
experiments/04-fork/
experiments/05-copy-on-write/
docs/fork-and-cow.md
```

### Milestone 4 — Socket IPC

Implement:

- Unix socket pair.
- Message framing.
- Partial reads.
- Partial writes.
- Request-response.
- Throughput benchmarks.

Deliverables:

```text
src/Ipc/SocketChannel.php
src/Ipc/MessageFramer.php
experiments/06-process-ipc/
benchmarks/ipc.php
```

### Milestone 5 — SysV IPC

Implement:

- Shared memory wrapper.
- Semaphore wrapper.
- Protected counter.
- Race-condition experiment.
- Shared-memory message exchange.

Deliverables:

```text
src/Ipc/SharedMemorySegment.php
src/Ipc/Semaphore.php
experiments/07-shared-memory/
docs/shared-memory.md
```

### Milestone 6 — Ring Buffer

Implement:

- Fixed-size slots.
- One producer.
- One consumer.
- Semaphore synchronization.
- Full/empty states.
- Throughput benchmark.

Deliverables:

```text
experiments/08-ring-buffer/
src/Ipc/RingBuffer.php
```

### Milestone 7 — mmap

Implement:

- File mapping.
- Reading.
- Writing.
- Flushing.
- Shared mapping between processes.

Deliverables:

```text
experiments/09-mmap/
src/Native/MappedFile.php
docs/mmap.md
```

### Milestone 8 — FFI

Implement:

- Native allocation.
- Native buffer.
- Read/write operations.
- Explicit cleanup.
- PHP versus native memory comparison.

Deliverables:

```text
experiments/10-ffi/
src/Native/FfiBuffer.php
docs/ffi-memory.md
```

### Milestone 9 — Benchmark Framework

Implement:

- Benchmark runner.
- Warm-up.
- Repetitions.
- JSON output.
- CSV output.
- Memory measurements.
- RSS measurements.

Deliverables:

```text
src/Benchmark/
benchmarks/
results/
```

---

## First MVP

The first version should remain intentionally small.

### MVP Features

- PHP 8.4+.
- Composer project.
- Docker environment.
- `MemorySnapshot`.
- `MemoryReporter`.
- `/proc/self/status` parser.
- Basic allocation experiments.
- Array/string experiments.
- Basic `fork()` experiment.
- Basic Copy-on-Write experiment.
- JSON results.
- PHPUnit tests.
- README with explanations.

### MVP Directory

```text
php-memory-lab/
├── README.md
├── composer.json
├── Dockerfile
├── Makefile
├── bin/
│   └── experiment
├── src/
│   └── Memory/
│       ├── MemorySnapshot.php
│       ├── MemoryReporter.php
│       └── ProcStatusReader.php
├── experiments/
│   ├── 01-memory-basics/
│   ├── 02-arrays-and-strings/
│   ├── 03-garbage-collection/
│   ├── 04-fork/
│   └── 05-copy-on-write/
├── tests/
│   └── Memory/
└── docs/
    ├── memory-model.md
    ├── php-memory-vs-rss.md
    └── fork-and-cow.md
```

---

## Suggested First Tasks

1. Create the repository.
2. Add `composer.json`.
3. Add Docker environment.
4. Add PHPUnit.
5. Implement `MemorySnapshot`.
6. Implement `ProcStatusReader`.
7. Implement `MemoryReporter`.
8. Add the first memory experiment.
9. Add JSON output.
10. Add tests.
11. Document PHP memory versus RSS.
12. Implement the first `fork()` experiment.
13. Implement the first Copy-on-Write experiment.
14. Add benchmark measurements.
15. Publish the first MVP.

---

## Definition of Done

The project is ready for the next stage when:

- All MVP experiments run inside Docker.
- Memory measurements are available in human-readable and JSON formats.
- `/proc/self/status` is parsed correctly.
- Parent and child processes can be measured independently.
- Copy-on-Write behavior is demonstrated with real measurements.
- Tests cover the memory-reporting layer.
- Each experiment explains:
   - What is being tested.
   - What is expected.
   - What was measured.
   - Why the result happened.
   - What limitations exist.
- README contains reproducible commands.
- Results include environment information.
- No benchmark is presented as a universal result.
- Unsafe experiments are isolated.
- IPC resources are cleaned up correctly.

---

## Future Extensions

After completing the core project, consider adding:

- `/proc/[pid]/maps` parser.
- `/proc/[pid]/smaps` parser.
- Page-fault measurements.
- `perf stat` integration.
- `strace` experiment wrappers.
- Signal experiments.
- Process supervisor experiments.
- Shared-memory object pools.
- Shared-memory hash tables.
- Lock-free structures.
- Atomic counters.
- Futex experiments through FFI.
- POSIX shared memory.
- POSIX semaphores.
- `eventfd`.
- `memfd_create`.
- Huge pages.
- Transparent Huge Pages.
- NUMA experiments.
- CPU affinity.
- Memory pressure experiments.
- OOM-killer experiments.
- cgroup memory limits.
- PHP worker-pool integration.
- RoadRunner worker-memory experiments.
- FrankenPHP worker-memory experiments.
- Swoole task-worker experiments.
- PHP-to-C integration.
- PHP-to-Go integration through FFI.
- Custom PHP extension experiments.

---

## Final Project Direction

The long-term goal is to understand how PHP, Linux processes, memory management, IPC, and native code interact in real backend systems.

The knowledge from this project can be applied to:

- PHP worker pools.
- Long-running PHP processes.
- RoadRunner.
- FrankenPHP worker mode.
- Swoole task workers.
- Shared-memory services.
- High-throughput IPC.
- Queue implementations.
- Memory-efficient caches.
- Storage engines.
- Database page caches.
- Native PHP extensions.
- FFI-based integrations.
- PHP-to-C integrations.
- PHP-to-Go experiments.

The final result should not simply be a collection of scripts.

It should become a structured learning system consisting of:

```text
Experiments
    ↓
Measurements
    ↓
Benchmarks
    ↓
Explanations
    ↓
Reusable primitives
    ↓
Real backend applications
```

The project should help answer not only:

> How do I implement this?

but also:

> What is happening inside PHP and Linux, why does it happen, and how can I prove it with measurements?
