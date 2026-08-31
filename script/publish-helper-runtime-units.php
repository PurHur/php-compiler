#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Publish freshly emitted helper units from build/helper-runtime-cache into
 * prelinked/helper-runtime/<arch>/units/ without re-running the full emit sweep.
 *
 * Usage:
 *   php script/publish-helper-runtime-units.php /ext/standard/SymlinkJitHelper.php ...
 */

use PHPCompiler\AOT\HelperRuntimeCache;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

if ($argc < 2) {
    fwrite(STDERR, "usage: php script/publish-helper-runtime-units.php /ext/.../FooJitHelper.php ...\n");
    exit(1);
}

$arch = HelperRuntimeCache::archKey();
$prelinkUnits = HelperRuntimeCache::prelinkedUnitsDir();
$archDir = \dirname($prelinkUnits);
if (!is_dir($prelinkUnits) && !mkdir($prelinkUnits, 0755, true) && !is_dir($prelinkUnits)) {
    fwrite(STDERR, "publish-helper-runtime-units: cannot create {$prelinkUnits}\n");
    exit(1);
}

$published = 0;
$publishedSlugs = [];
for ($i = 1; $i < $argc; ++$i) {
    $path = $argv[$i];
    $slug = HelperRuntimeCache::slugFor($path);
    $buildDir = HelperRuntimeCache::unitDir($slug);
    $sourceAbs = HelperRuntimeCache::resolveUnitSource($root, $path);
    $manifest = HelperRuntimeCache::unitManifest($slug);
    if (null === $sourceAbs || null === $manifest
        || !HelperRuntimeCache::manifestFingerprintMatches($manifest, $sourceAbs)
        || !is_file($buildDir.'/unit.o') || !is_file($buildDir.'/unit.bc')) {
        fwrite(STDERR, "publish-helper-runtime-units: skip {$slug} — not fresh/complete in build cache\n");
        exit(1);
    }
    $dest = $prelinkUnits.'/'.$slug;
    if (!is_dir($dest) && !mkdir($dest, 0755, true) && !is_dir($dest)) {
        fwrite(STDERR, "publish-helper-runtime-units: cannot create {$dest}\n");
        exit(1);
    }
    foreach (['unit.o', 'unit.bc', 'manifest.json'] as $name) {
        copy($buildDir.'/'.$name, $dest.'/'.$name);
    }
    @unlink($dest.'/failed.json');
    $publishedSlugs[$slug] = true;
    ++$published;
    fwrite(STDOUT, "published {$slug}\n");
}

$committedDirs = glob($prelinkUnits.'/*', GLOB_ONLYDIR) ?: [];
$totalBytes = 0;
foreach ($committedDirs as $dir) {
    foreach (['unit.o', 'unit.bc', 'manifest.json'] as $name) {
        $totalBytes += (int) @filesize($dir.'/'.$name);
    }
}
file_put_contents($archDir.'/manifest.json', json_encode([
    'version' => 1,
    'generated_at' => gmdate('c'),
    'arch' => $arch,
    'role' => 'committed per-arch split-compilation helper units (#15889) — consumed via PHP_COMPILER_HELPER_RUNTIME_O=1; stale units are skipped per fingerprint and recompiled locally',
    'core_fingerprint' => HelperRuntimeCache::coreFingerprint(),
    'llvm_identity_token' => HelperRuntimeCache::llvmIdentityToken(),
    'unit_count' => \count($committedDirs),
    'published_fresh' => $published,
    'kept_live_unpublished' => 0,
    'total_bytes' => $totalBytes,
    'refresh' => 'php script/publish-helper-runtime-units.php (targeted publish)',
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");

fwrite(STDOUT, sprintf(
    "publish-helper-runtime-units: %s — %d unit(s) published, %d committed total\n",
    $arch,
    $published,
    \count($committedDirs)
));
