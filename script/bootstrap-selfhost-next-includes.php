#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * List the next lib/ units to add to compiler_minimal toward bin/vm.php (issue #212).
 *
 * Uses LiteralIncludeDiscovery from bin/vm.php (transitive literal require_once) plus
 * the vm execution spine (Runtime → VMContext → VM).
 */

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

$runtime = new PHPCompiler\Runtime(PHPCompiler\Runtime::MODE_AOT);
$bundleEntry = $root.'/test/selfhost/compiler_minimal/main.php';
$limit = 10;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int) substr($arg, 8));
    } elseif (str_starts_with($arg, '--bundle=')) {
        $rel = substr($arg, 9);
        if ('' !== $rel) {
            $bundleEntry = str_starts_with($rel, '/') ? $rel : $root.'/'.$rel;
        }
    }
}

preg_match_all('#lib/[^"\']+#', (string) file_get_contents($bundleEntry), $matches);
$inBundle = array_flip($matches[0]);

$seen = [];
$ordered = [];
$queue = [
    $root.'/bin/vm.php',
    $root.'/lib/Runtime.php',
    $root.'/lib/VM.php',
];
try {
    foreach (PHPCompiler\Web\LiteralIncludeDiscovery::discoverAbsolutePaths($runtime, $bundleEntry) as $include) {
        $queue[] = $include;
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "bundle discovery: {$e->getMessage()}\n");
}
// vm.php execution spine (no literal require_once in bin/vm.php)
foreach (
    [
        'lib/Web/Superglobals.php',
        'lib/JIT.php',
        'lib/JIT/Context.php',
        'lib/NullSafeLivenessDetector.php',
    ] as $rel
) {
    $queue[] = $root.'/'.$rel;
}
while ([] !== $queue) {
    $file = array_shift($queue);
    $file = realpath($file) ?: $file;
    if (isset($seen[$file])) {
        continue;
    }
    $seen[$file] = true;
    if (str_starts_with($file, $root.'/lib/')) {
        $ordered[] = substr($file, strlen($root) + 1);
    }
    try {
        $includes = PHPCompiler\Web\LiteralIncludeDiscovery::discoverDirectAbsolutePaths($runtime, $file);
    } catch (\Throwable $e) {
        fwrite(STDERR, "skip parse {$file}: {$e->getMessage()}\n");
        $includes = [];
    }
    foreach ($includes as $include) {
        if (!isset($seen[$include])) {
            $queue[] = $include;
        }
    }
}

$next = [];
foreach ($ordered as $rel) {
    if (!isset($inBundle[$rel])) {
        $next[] = $rel;
    }
}
$next = array_values(array_unique($next));

fwrite(STDOUT, basename(dirname($bundleEntry)).' bundle: '.count($inBundle)." lib units\n");
fwrite(STDOUT, "literal spine from bin/vm.php: ".count($ordered)." lib units\n");
fwrite(STDOUT, "next {$limit} toward vm.php closure:\n");
foreach (array_slice($next, 0, $limit) as $rel) {
    fwrite(STDOUT, "  {$rel}\n");
}
