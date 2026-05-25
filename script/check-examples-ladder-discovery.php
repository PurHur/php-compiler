#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guard examples/ numbered ladder against ExamplesCompileTest drift (issue #1913).
 *
 * Fails when:
 * - A shipped examples/00N-* directory (example.php or public/index.php entry) is not wired in
 *   test/unit/ExamplesCompileTest.php discovery (glob, manifest provider, or explicit path).
 * - ExamplesCompileTest references a missing examples/ path on disk.
 *
 * Usage:
 *   php script/check-examples-ladder-discovery.php
 *
 * CI gate (default on): EXAMPLES_LADDER_DISCOVERY_GATE=1 in script/ci-defaults.env (#1913).
 * Opt-out: EXAMPLES_LADDER_DISCOVERY_GATE=0 for doc-only iteration.
 */

$root = dirname(__DIR__);
$examplesRoot = $root.'/examples';
$testFile = $root.'/test/unit/ExamplesCompileTest.php';

if (!is_dir($examplesRoot)) {
    fwrite(STDERR, "check-examples-ladder-discovery: missing {$examplesRoot}\n");
    exit(1);
}
if (!is_readable($testFile)) {
    fwrite(STDERR, "check-examples-ladder-discovery: missing {$testFile}\n");
    exit(1);
}

$filesystem = discoverFilesystemLadder($examplesRoot);
$test = parseExamplesCompileTest($testFile);
$errors = [];

foreach ($filesystem as $name => $meta) {
    if ($meta['example_php']) {
        // provideExamples() glob covers example.php entries.
        continue;
    }
    if ($meta['public_index'] && !in_array($name, $test['referenced_dirs'], true)) {
        $errors[] = "examples/{$name}: public/index.php entry not referenced in ExamplesCompileTest.php (#1913)";
    }
}

foreach ($filesystem as $name => $meta) {
    if ($meta['phpc_json'] && $meta['example_php'] && !in_array($name, $test['manifest_dirs'], true)) {
        $errors[] = "examples/{$name}: phpc.json web example missing from provideWebExampleManifestDirs()";
    }
}

foreach ($test['manifest_dirs'] as $name) {
    $dir = $examplesRoot.'/'.$name;
    if (!is_dir($dir)) {
        $errors[] = "ExamplesCompileTest provideWebExampleManifestDirs: missing directory {$dir}";
    }
}

foreach ($test['literal_paths'] as $rel) {
    $path = $root.'/'.$rel;
    if (!is_file($path) && !is_dir($path)) {
        $errors[] = "ExamplesCompileTest references missing path: {$rel}";
    }
}

if ([] !== $errors) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-examples-ladder-discovery: {$err}\n");
    }
    fwrite(STDERR, "check-examples-ladder-discovery: FAILED (sync examples/ ↔ ExamplesCompileTest; see #1913)\n");
    exit(1);
}

fwrite(STDOUT, 'check-examples-ladder-discovery: OK ('.count($filesystem)." shipped examples)\n");
exit(0);

/**
 * @return array<string, array{example_php: bool, public_index: bool, phpc_json: bool}>
 */
function discoverFilesystemLadder(string $examplesRoot): array
{
    $out = [];
    foreach (glob($examplesRoot.'/[0-9]*/', GLOB_ONLYDIR) ?: [] as $dir) {
        $name = basename($dir);
        if (!preg_match('/^\d{3}-/', $name)) {
            continue;
        }
        $hasExample = is_file($dir.'/example.php');
        $hasPublicIndex = is_file($dir.'/public/index.php');
        if (!$hasExample && !$hasPublicIndex) {
            continue;
        }
        $out[$name] = [
            'example_php' => $hasExample,
            'public_index' => $hasPublicIndex,
            'phpc_json' => is_file($dir.'/phpc.json'),
        ];
    }
    ksort($out);

    return $out;
}

/**
 * @return array{manifest_dirs: list<string>, referenced_dirs: list<string>, literal_paths: list<string>}
 */
function parseExamplesCompileTest(string $testFile): array
{
    $body = (string) file_get_contents($testFile);
    $manifestDirs = [];
    if (preg_match('/function provideWebExampleManifestDirs\(\): array\s*\{.*?return \[(.*?)\];/s', $body, $block)) {
        if (preg_match_all("/'(\d{3}-[^']+)'\s*=>/", $block[1], $keys)) {
            $manifestDirs = array_values(array_unique($keys[1]));
        }
    }

    preg_match_all('#examples/(\d{3}-[A-Za-z0-9]+)#', $body, $dirRefs);
    $referencedDirs = array_values(array_unique($dirRefs[1]));

    preg_match_all(
        '#[\'"](examples/\d{3}-[A-Za-z0-9]+(?:/example\.php|/public/index\.php)?)[\'"]#',
        $body,
        $pathRefs
    );
    $literalPaths = array_values(array_unique($pathRefs[1]));

    return [
        'manifest_dirs' => $manifestDirs,
        'referenced_dirs' => $referencedDirs,
        'literal_paths' => $literalPaths,
    ];
}
