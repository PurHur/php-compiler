#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Export external-method stub lists from a spine-chunk compile log (#36147).
 *
 * Usage:
 *   php script/spine-chunk-stub-export.php build/micro/chunk/ds.log
 *   php script/spine-chunk-stub-export.php --write build/micro/chunk/ds.stubs.json build/micro/chunk/ds.log
 *
 * Output JSON:
 *   { "chunk": "ds", "stub_count": 77, "stubs": ["object::int", ...] }
 */

$write = false;
$outPath = null;
$inputs = [];
$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); ++$i) {
    $arg = $args[$i];
    if ('--write' === $arg) {
        $write = true;
        if (isset($args[$i + 1]) && !str_starts_with($args[$i + 1], '-')) {
            $outPath = $args[++$i];
        }
        continue;
    }
    if (str_starts_with($arg, '--out=')) {
        $outPath = substr($arg, 6);
        $write = true;
        continue;
    }
    $inputs[] = $arg;
}

if ([] === $inputs) {
    fwrite(STDERR, "spine-chunk-stub-export: missing log path(s)\n");
    exit(2);
}

$stubs = [];
foreach ($inputs as $path) {
    if (!is_readable($path)) {
        fwrite(STDERR, "spine-chunk-stub-export: unreadable {$path}\n");
        exit(2);
    }
    $text = (string) file_get_contents($path);
    if (str_ends_with($path, '.json')) {
        $decoded = json_decode($text, true);
        if (is_array($decoded) && isset($decoded['stubs']) && is_array($decoded['stubs'])) {
            foreach ($decoded['stubs'] as $name) {
                if (is_string($name) && '' !== $name) {
                    $stubs[strtolower($name)] = true;
                }
            }
            continue;
        }
    }
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        if (!str_contains($line, 'external method stubs')) {
            continue;
        }
        if (!preg_match('/— class not in this module \(#579\): (.+)$/u', $line, $m)) {
            continue;
        }
        $tail = trim($m[1]);
        if (str_ends_with($tail, ', …')) {
            $tail = substr($tail, 0, -strlen(', …'));
        }
        foreach (explode(', ', $tail) as $name) {
            $name = strtolower(trim($name));
            if ('' !== $name) {
                $stubs[$name] = true;
            }
        }
    }
}

$names = array_keys($stubs);
sort($names, SORT_STRING);

$chunk = 'unknown';
$first = $inputs[0];
if (preg_match('#/([^/]+)\.log$#', $first, $m)) {
    $chunk = $m[1];
}

$payload = [
    'chunk' => $chunk,
    'stub_count' => count($names),
    'stubs' => $names,
    'sources' => array_map(static fn (string $p): string => $p, $inputs),
];

$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

if ($write) {
    $target = $outPath ?? preg_replace('/\.log$/', '.stubs.json', $first);
    if (!is_string($target) || '' === $target) {
        fwrite(STDERR, "spine-chunk-stub-export: cannot derive output path\n");
        exit(2);
    }
    $dir = dirname($target);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "spine-chunk-stub-export: cannot create {$dir}\n");
        exit(2);
    }
    file_put_contents($target, $json);
    fwrite(STDOUT, "spine-chunk-stub-export: wrote {$target} ({$payload['stub_count']} stubs)\n");
    exit(0);
}

fwrite(STDOUT, $json);
exit(0);
