#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * List the next lib/ and ext/ units to add to a spine bundle toward bin/vm.php (issue #212, #1922, #1945).
 *
 * Walks transitive literal require/require_once edges from bin/vm.php plus the
 * vm execution spine seeds, then diffs against the bundle's entries.
 *
 * Token-based literal-include scan (token_get_all): resolves `__DIR__ . '/x.php'`
 * and plain string operands only — the same "literal include" shape the spine
 * uses. Replaces the previous Runtime/CFG-based discovery, which booted the full
 * compiler and CFG-parsed every reachable file (~15 min, ~0.6 GB); this pass is
 * a few seconds and O(one file) memory.
 *
 * Usage:
 *   php script/bootstrap-selfhost-next-includes.php \
 *     --bundle=test/selfhost/compiler_lib_spine_smoke/main.php --limit=25
 */

$root = dirname(__DIR__);

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

preg_match_all('#(?:lib|ext)/[^"\']+#', (string) file_get_contents($bundleEntry), $matches);
$inBundle = array_flip($matches[0]);

/** @return list<string> resolved absolute paths of literal includes in $file */
function literal_includes(string $file): array
{
    $code = @file_get_contents($file);
    if (false === $code || '' === $code) {
        return [];
    }
    $tokens = token_get_all($code);
    $count = count($tokens);
    $paths = [];
    for ($i = 0; $i < $count; ++$i) {
        $token = $tokens[$i];
        if (!is_array($token)) {
            continue;
        }
        if (!in_array($token[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) {
            continue;
        }
        $literal = '';
        for ($j = $i + 1; $j < $count; ++$j) {
            $part = $tokens[$j];
            if (';' === $part || ',' === $part) {
                break;
            }
            if ('.' === $part || '(' === $part || ')' === $part) {
                continue;
            }
            if (!is_array($part)) {
                $literal = null;
                break;
            }
            if (in_array($part[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (T_DIR === $part[0]) {
                $literal .= dirname($file);

                continue;
            }
            if (T_CONSTANT_ENCAPSED_STRING === $part[0]) {
                $raw = substr($part[1], 1, -1);
                $literal .= str_replace(['\\\\', "\\'", '\\"'], ['\\', "'", '"'], $raw);

                continue;
            }
            // Non-literal operand (variable, function call, …) — not a literal include.
            $literal = null;
            break;
        }
        if (null !== $literal && '' !== $literal) {
            $resolved = realpath($literal);
            if (false !== $resolved) {
                $paths[] = $resolved;
            }
        }
    }

    return $paths;
}

$seen = [];
$ordered = [];
$queue = [
    $root.'/bin/vm.php',
    $root.'/lib/Runtime.php',
    $root.'/lib/VM.php',
];
foreach (literal_includes($bundleEntry) as $include) {
    $queue[] = $include;
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
    if (str_starts_with($file, $root.'/lib/') || str_starts_with($file, $root.'/ext/')) {
        $ordered[] = substr($file, strlen($root) + 1);
    }
    foreach (literal_includes($file) as $include) {
        if (!isset($seen[$include])) {
            $queue[] = $include;
        }
    }
}

// Inventory files missed by the literal walk (e.g. autoload-only units) still
// belong in the spine — merge in the coverage checker's ground truth when the
// inventory doc is present.
$inventoryDoc = $root.'/docs/bootstrap-inventory.md';
if (is_readable($inventoryDoc)) {
    preg_match_all('#^\| `((?:lib|ext)/[^`]+\.php)`#m', (string) file_get_contents($inventoryDoc), $m);
    foreach ($m[1] as $rel) {
        $ordered[] = $rel;
    }
}

$next = [];
foreach ($ordered as $rel) {
    if (!isset($inBundle[$rel])) {
        $next[] = $rel;
    }
}
$next = array_values(array_unique($next));

fwrite(STDOUT, basename(dirname($bundleEntry)).' bundle: '.count($inBundle)." lib/ext units\n");
fwrite(STDOUT, 'literal spine from bin/vm.php: '.count(array_unique($ordered))." lib/ext units\n");
fwrite(STDOUT, "next {$limit} toward vm.php closure:\n");
foreach (array_slice($next, 0, $limit) as $rel) {
    fwrite(STDOUT, "  {$rel}\n");
}
