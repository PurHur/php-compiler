#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generate M5 vendor prelink bundles and attempt AOT → .o (issue #1416).
 *
 * Usage:
 *   php script/bootstrap-vendor-objects.php              # generate bundles + manifest
 *   php script/bootstrap-vendor-objects.php --compile    # AOT via gen-0 driver resolver (needs LLVM 9; #2849)
 *   php script/bootstrap-vendor-objects.php --check        # manifest + bundles fresh
 */

$root = dirname(__DIR__);
require $root.'/script/bootstrap-vendor-prelink-lib.php';

$applyPatches = $root.'/script/apply-patches.sh';
if (is_file($applyPatches)) {
    // Vendor prelink bundles must match patched php-cfg overlays (ArrowFunction, etc.; #2687).
    passthru('bash '.escapeshellarg($applyPatches).' 2>/dev/null', $patchExit);
    unset($patchExit);
}

$compile = in_array('--compile', $argv, true);
$check = in_array('--check', $argv, true);
$one = null;
foreach ($argv as $arg) {
    if (str_starts_with((string) $arg, '--one=')) {
        $one = substr((string) $arg, strlen('--one='));
        break;
    }
}
$bundlesDir = $root.'/test/bootstrap-vendor-prelink/generated';
$prelinkDir = $root.'/prelinked/bootstrap-vendor';
$manifestPath = $prelinkDir.'/manifest.json';
$compileBin = $root.'/bin/compile.php';

$manifest = bootstrapVendorPrelinkReadManifest($manifestPath) ?? bootstrapVendorPrelinkEmptyManifest($root);
$vendorPresent = bootstrapVendorPrelinkVendorTreePresent($root);

if ($check && !$vendorPresent) {
    exit(bootstrapVendorPrelinkColdBootCheck($root, $manifestPath, $manifest));
}

if ($compile && !$vendorPresent) {
    exit(bootstrapVendorPrelinkColdBootCompile($root, $manifestPath, $manifest, $one));
}

foreach (BOOTSTRAP_VENDOR_PRELINK_PACKAGES as $package => $role) {
    $slug = bootstrapVendorPrelinkSlug($package);
    $files = bootstrapVendorPrelinkLibPhpFiles($root, $package);
    $bundleRel = 'test/bootstrap-vendor-prelink/generated/'.$slug.'_bundle.php';
    $bundleAbs = $root.'/'.$bundleRel;

    if (!is_dir($bundlesDir)) {
        mkdir($bundlesDir, 0775, true);
    }

    $bundleSource = bootstrapVendorPrelinkRenderBundle($package, $role, $files, $bundleRel);
    $existingBundle = is_file($bundleAbs) ? (string) file_get_contents($bundleAbs) : null;

    $manifest['packages'][$package] = [
        'role' => $role,
        'slug' => $slug,
        'bundle' => $bundleRel,
        'object' => 'prelinked/bootstrap-vendor/'.$slug.'.o',
        'archive' => 'prelinked/bootstrap-vendor/'.$slug.'.a',
        'php_files' => count($files),
        'status' => 0 === count($files) ? 'missing_vendor' : 'bundle_ok',
        'blocker' => 0 === count($files) ? 'vendor package not installed (composer install)' : null,
    ];

    if ($check && $existingBundle !== $bundleSource) {
        fwrite(STDERR, "Stale bundle {$bundleRel}; run: php script/bootstrap-vendor-objects.php\n");
        exit(1);
    }

    if (!$check) {
        file_put_contents($bundleAbs, $bundleSource);
    }
}

if ($check) {
    if (!is_file($manifestPath)) {
        fwrite(STDERR, "Missing {$manifestPath}; run: php script/bootstrap-vendor-objects.php\n");
        exit(1);
    }
    $onDisk = bootstrapVendorPrelinkReadManifest($manifestPath);
    if (!is_array($onDisk) || !isset($onDisk['packages'])) {
        fwrite(STDERR, "Invalid {$manifestPath}\n");
        exit(1);
    }
    foreach (BOOTSTRAP_VENDOR_PRELINK_PACKAGES as $package => $role) {
        $slug = bootstrapVendorPrelinkSlug($package);
        $bundleRel = 'test/bootstrap-vendor-prelink/generated/'.$slug.'_bundle.php';
        $bundleAbs = $root.'/'.$bundleRel;
        if (!is_file($bundleAbs)) {
            fwrite(STDERR, "Missing bundle {$bundleRel}; run: php script/bootstrap-vendor-objects.php\n");
            exit(1);
        }
        $files = bootstrapVendorPrelinkLibPhpFiles($root, $package);
        $expected = bootstrapVendorPrelinkRenderBundle($package, $role, $files, $bundleRel);
        if ((string) file_get_contents($bundleAbs) !== $expected) {
            fwrite(STDERR, "Stale bundle {$bundleRel}; run: php script/bootstrap-vendor-objects.php\n");
            exit(1);
        }
        $expectedFiles = count($files);
        $onDiskFiles = (int) ($onDisk['packages'][$package]['php_files'] ?? -1);
        if ($expectedFiles !== $onDiskFiles) {
            fwrite(STDERR, "Stale manifest php_files for {$package}; run: php script/bootstrap-vendor-objects.php\n");
            exit(1);
        }
    }
    exit(0);
}

bootstrapVendorPrelinkWriteManifest($manifestPath, $manifest);

if (!$compile) {
    fwrite(STDOUT, "Wrote bundles under test/bootstrap-vendor-prelink/generated/ and {$manifestPath}\n");
    fwrite(STDOUT, "Next: php script/bootstrap-vendor-objects.php --compile (or make bootstrap-vendor-objects)\n");
    exit(0);
}

if (!is_file($compileBin)) {
    fwrite(STDERR, "Missing {$compileBin}\n");
    exit(1);
}

$llvm = getenv('PHP_COMPILER_LLVM_PATH') ?: '';
if ('' === $llvm || !is_file($llvm.'/libLLVM-9.so.1')) {
    fwrite(STDERR, "bootstrap-vendor-objects: LLVM 9 not found (set PHP_COMPILER_LLVM_PATH)\n");
    exit(2);
}

putenv('PHP_COMPILER_SELFHOST_AOT=0');
putenv('PHP_COMPILER_VENDOR_PRELINK=1');
putenv('PHP_COMPILER_KEEP_OBJECT_FILE=1');

$compileInvoker = bootstrapVendorPrelinkResolveCompileInvoker($root);
fwrite(
    STDERR,
    'bootstrap-vendor-objects: compile invoker='.$compileInvoker['mode']
    .' ('.($compileInvoker['argv'][0] ?? 'unknown').") (#2849)\n"
);

$failures = bootstrapVendorPrelinkCompilePackages($root, $manifest, $one);

bootstrapVendorPrelinkWriteManifest($manifestPath, $manifest);

if ($failures > 0) {
    fwrite(STDERR, "bootstrap-vendor-objects: {$failures} package(s) failed (manifest updated)\n");
    exit(1);
}

fwrite(STDOUT, "bootstrap-vendor-objects: all prelink objects OK\n");
exit(0);
