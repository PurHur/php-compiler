#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard examples/ ladder discovery against ExamplesCompileTest drift (issue #1913).
 *
 * Checks:
 *  - Each shipped examples/00x-* dir (example.php or public/index.php) is covered by the test harness
 *  - ExamplesCompileTest does not reference missing example paths
 *  - provideWebExampleManifestDirs lists every example.php + phpc.json web manifest
 *  - examples/README.md run matrix has a row per shipped example
 *
 * Usage:
 *   php script/check-examples-ladder-discovery.php
 */

$root = dirname(__DIR__);
$examplesRoot = $root.'/examples';
$testFile = $root.'/test/unit/ExamplesCompileTest.php';
$readme = $root.'/examples/README.md';

$errors = [];

if (!is_readable($testFile)) {
    fwrite(STDERR, "check-examples-ladder-discovery: missing {$testFile}\n");
    exit(1);
}

$testBody = (string) file_get_contents($testFile);
$onDisk = discover_shipped_examples($examplesRoot);

if (!str_contains($testBody, "glob(\$root.'/*/example.php')")) {
    $errors[] = 'ExamplesCompileTest: provideExamples must glob examples/*/example.php';
}

$manifestDirs = parse_web_manifest_dirs($testBody);
$testRefs = parse_test_example_refs($testBody);

foreach ($onDisk as $name => $meta) {
    if ('example.php' === $meta['entry_kind']) {
        continue;
    }
    $needle = 'examples/'.$name;
    if (!path_ref_in_test($testRefs, $needle)) {
        $errors[] = "ExamplesCompileTest: missing reference to {$needle} (entry {$meta['entry']})";
    }
}

foreach ($testRefs as $ref) {
    $rel = substr($ref, strlen('examples/'));
    $dir = $examplesRoot.'/'.$rel;
    if (!is_dir($dir)) {
        $errors[] = "ExamplesCompileTest: references missing directory {$ref}";
        continue;
    }
    if (!isset($onDisk[$rel])) {
        $errors[] = "ExamplesCompileTest: {$ref} has no example.php or public/index.php entry";
    }
}

foreach ($manifestDirs as $name) {
    if (!isset($onDisk[$name])) {
        $errors[] = "provideWebExampleManifestDirs: {$name} not found under examples/";
        continue;
    }
    if ('example.php' !== $onDisk[$name]['entry_kind']) {
        $errors[] = "provideWebExampleManifestDirs: {$name} entry is not example.php";
    }
    if (!is_file($examplesRoot.'/'.$name.'/phpc.json')) {
        $errors[] = "provideWebExampleManifestDirs: {$name} missing phpc.json";
    }
}

foreach ($onDisk as $name => $meta) {
    if ('example.php' !== $meta['entry_kind']) {
        continue;
    }
    if (!is_file($examplesRoot.'/'.$name.'/phpc.json')) {
        continue;
    }
    if (!in_array($name, $manifestDirs, true)) {
        $errors[] = "provideWebExampleManifestDirs: missing {$name} (has example.php + phpc.json)";
    }
}

if (is_readable($readme)) {
    $readmeBody = (string) file_get_contents($readme);
    foreach (array_keys($onDisk) as $name) {
        if (!preg_match('/\| \['.preg_quote($name, '/').'\]/', $readmeBody)) {
            $errors[] = "examples/README.md: run matrix missing row for {$name}";
        }
    }
} else {
    $errors[] = "examples/README.md missing (cannot verify run matrix)";
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-examples-ladder-discovery: {$err}\n");
    }
    fwrite(STDERR, "check-examples-ladder-discovery: FAILED (see #1913)\n");
    exit(1);
}

fwrite(STDOUT, 'check-examples-ladder-discovery: OK ('.count($onDisk)." examples)\n");
exit(0);

/**
 * @return array<string, array{entry: string, entry_kind: string}>
 */
function discover_shipped_examples(string $examplesRoot): array
{
    $out = [];
    foreach (glob($examplesRoot.'/[0-9]*', GLOB_ONLYDIR) ?: [] as $dir) {
        $name = basename($dir);
        if (!preg_match('/^\d{3}-/', $name)) {
            continue;
        }
        $examplePhp = $dir.'/example.php';
        $publicIndex = $dir.'/public/index.php';
        if (is_file($examplePhp)) {
            $out[$name] = ['entry' => 'example.php', 'entry_kind' => 'example.php'];
        } elseif (is_file($publicIndex)) {
            $out[$name] = ['entry' => 'public/index.php', 'entry_kind' => 'public/index.php'];
        }
    }
    ksort($out);

    return $out;
}

/**
 * @return list<string>
 */
function parse_web_manifest_dirs(string $testBody): array
{
    if (!preg_match('/function provideWebExampleManifestDirs\(\): array\s*\{([^}]+)\}/s', $testBody, $m)) {
        return [];
    }
    preg_match_all("/'(\d{3}-[^']+)'\s*=>/", $m[1], $names);

    return $names[1] ?? [];
}

/**
 * @return list<string>
 */
function parse_test_example_refs(string $testBody): array
{
    preg_match_all('#examples/(\d{3}-[A-Za-z0-9-]+)#', $testBody, $m);
    $refs = [];
    foreach ($m[1] ?? [] as $name) {
        $refs['examples/'.$name] = true;
    }

    return array_keys($refs);
}

/**
 * @param list<string> $testRefs
 */
function path_ref_in_test(array $testRefs, string $needle): bool
{
    foreach ($testRefs as $ref) {
        if ($ref === $needle || str_starts_with($ref, $needle.'/')) {
            return true;
        }
    }

    return false;
}
