#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Report freshness of the committed per-arch helper-unit cache (#15889).
 *
 * REPORT-ONLY by design: a stale committed unit is skipped per fingerprint by
 * HelperRuntimeCache and recompiled locally, so staleness can only cost build
 * time, never correctness. This check tells maintainers when a refresh
 * (`php script/emit-helper-runtime-object.php --prelink`) is worth committing.
 *
 * Exit 0 always, unless --strict is passed (then stale/absent -> exit 1).
 *
 * Usage:
 *   php script/check-helper-runtime-prelink.php            # report
 *   php script/check-helper-runtime-prelink.php --strict   # gate
 */

use PHPCompiler\AOT\HelperRuntimeCache;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

$strict = in_array('--strict', $argv, true);
$arch = HelperRuntimeCache::archKey();
$unitsDir = HelperRuntimeCache::prelinkedUnitsDir();

if (!is_dir($unitsDir)) {
    fwrite(STDOUT, "check-helper-runtime-prelink: no committed cache for {$arch} (cold builds compile helpers locally)\n");
    exit($strict ? 1 : 0);
}

$fresh = 0;
$stale = 0;
$broken = 0;
$manifests = glob($unitsDir.'/*/manifest.json') ?: [];
if ([] === $manifests) {
    // Empty cache previously reported "0 fresh" and exited 0 even under --strict (#22638).
    fwrite(STDOUT, "check-helper-runtime-prelink: {$arch} — 0 units (no manifest.json under {$unitsDir})\n");
    exit($strict ? 1 : 0);
}
foreach ($manifests as $manifestPath) {
    $unitDir = dirname($manifestPath);
    $slug = basename($unitDir);
    $manifest = HelperRuntimeCache::unitManifest($slug, $unitDir);
    if (null === $manifest || !is_file($unitDir.'/unit.o') || !is_file($unitDir.'/unit.bc')) {
        ++$broken;
        fwrite(STDOUT, "  BROKEN {$slug} (incomplete unit)\n");

        continue;
    }
    $sourceAbs = HelperRuntimeCache::resolveUnitSource($root, (string) $manifest['unit']);
    if (null === $sourceAbs) {
        ++$stale;
        fwrite(STDOUT, "  STALE  {$slug} (source gone: {$manifest['unit']})\n");

        continue;
    }
    if (HelperRuntimeCache::unitFingerprint($sourceAbs) !== $manifest['fingerprint']) {
        ++$stale;
        fwrite(STDOUT, "  STALE  {$slug} ({$manifest['unit']})\n");

        continue;
    }
    ++$fresh;
}

fwrite(STDOUT, sprintf(
    "check-helper-runtime-prelink: %s — %d fresh, %d stale, %d broken%s\n",
    $arch,
    $fresh,
    $stale,
    $broken,
    ($stale + $broken) > 0 ? ' — refresh: php script/emit-helper-runtime-object.php --prelink' : ''
));
exit($strict && ($stale + $broken) > 0 ? 1 : 0);
