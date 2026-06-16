#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * M5 vendor prelink native rebuild audit (#8718).
 *
 * Rebuilds each vendor bundle from prelinked/bootstrap-vendor/sources/ using the
 * native compile driver (BOOTSTRAP_M5_VENDOR_ALLOW_ZEND=0) and compares object
 * SHA-256 hashes with committed prelinked/bootstrap-vendor/*.o sidecars.
 *
 * Usage:
 *   php script/bootstrap-vendor-native-rebuild-audit.php
 *   BOOTSTRAP_M5_VENDOR_ALLOW_ZEND=0 ./script/bootstrap-vendor-native-rebuild-audit.sh
 */

$root = dirname(__DIR__);
require $root.'/script/bootstrap-vendor-prelink-lib.php';

$applyPatches = $root.'/script/apply-patches.sh';
if (is_file($applyPatches)) {
    passthru('bash '.escapeshellarg($applyPatches).' 2>/dev/null', $patchExit);
    unset($patchExit);
}

putenv('BOOTSTRAP_M5_VENDOR_ALLOW_ZEND=0');
putenv('BOOTSTRAP_GEN0_ZEND_ONLY=0');
putenv('PHP_COMPILER_VENDOR_PRELINK=1');
putenv('PHP_COMPILER_SELFHOST_AOT=0');
putenv('PHP_COMPILER_KEEP_OBJECT_FILE=1');

$llvm = getenv('PHP_COMPILER_LLVM_PATH') ?: '';
if ('' === $llvm || !is_file($llvm.'/libLLVM-9.so.1')) {
    fwrite(STDERR, "bootstrap-vendor-native-rebuild-audit: LLVM 9 required (set PHP_COMPILER_LLVM_PATH)\n");

    exit(2);
}

if (!bootstrapVendorPrelinkSourcesTreePresent($root)) {
    fwrite(STDERR, "bootstrap-vendor-native-rebuild-audit: prelinked/bootstrap-vendor/sources/ incomplete (#2881)\n");

    exit(1);
}

$invoker = bootstrapVendorPrelinkResolveCompileInvoker($root);
if ('native' !== $invoker['mode']) {
    fwrite(STDERR, "bootstrap-vendor-native-rebuild-audit: native compile driver required (no Zend gen-0)\n");
    fwrite(STDERR, "bootstrap-vendor-native-rebuild-audit: build one of:\n");
    fwrite(STDERR, "  make bootstrap-selfhost-driver-smoke\n");
    fwrite(STDERR, "  ./script/bootstrap-ensure-inventory-argv-driver.sh\n");

    exit(1);
}

$marker = null;
if (bootstrapVendorPrelinkVendorTreePresent($root)) {
    fwrite(STDERR, "bootstrap-vendor-native-rebuild-audit: vendor/ must be absent for audit (move aside composer tree)\n");

    exit(1);
}

$marker = bootstrapVendorPrelinkMaterializeVendorTree($root);
if (null === $marker) {
    fwrite(STDERR, "bootstrap-vendor-native-rebuild-audit: failed to materialize vendor/ from sources\n");

    exit(1);
}

bootstrapVendorPrelinkEnsureDriverSidecars($root);

$auditDir = $root.'/build/bootstrap-vendor-native-rebuild-audit';
if (is_dir($auditDir)) {
    foreach (glob($auditDir.'/*') ?: [] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
} else {
    mkdir($auditDir, 0775, true);
}

fwrite(STDOUT, "bootstrap-vendor-native-rebuild-audit: invoker={$invoker['mode']} ({$invoker['argv'][0]})\n");
fwrite(STDOUT, "bootstrap-vendor-native-rebuild-audit: comparing rebuilt .o SHA-256 vs committed sidecars\n\n");

$results = [];
$drift = 0;
$failed = 0;
foreach (BOOTSTRAP_VENDOR_PRELINK_PACKAGES as $package => $role) {
    $audit = bootstrapVendorPrelinkAuditPackage($root, $package, $invoker, $auditDir);
    $results[] = $audit;
    $status = (string) $audit['status'];
    $line = sprintf(
        '%-22s %-18s committed=%s rebuilt=%s sources=%s',
        $package,
        $status,
        substr((string) ($audit['committed_hash'] ?? 'null'), 0, 12),
        substr((string) ($audit['rebuilt_hash'] ?? 'null'), 0, 12),
        substr((string) ($audit['sources_hash'] ?? 'null'), 0, 12)
    );
    if ('match' === $status) {
        fwrite(STDOUT, "OK   {$line}\n");
        continue;
    }
    if ('drift' === $status) {
        ++$drift;
        fwrite(STDERR, "DRIFT {$line}\n");
        continue;
    }
    ++$failed;
    $blocker = (string) ($audit['blocker'] ?? $status);
    fwrite(STDERR, "FAIL {$line} — {$blocker}\n");
}

bootstrapVendorPrelinkCleanupMaterializedVendorTree($root, $marker);

fwrite(STDOUT, "\n");
fwrite(STDOUT, sprintf(
    "bootstrap-vendor-native-rebuild-audit: summary match=%d drift=%d failed=%d\n",
    count($results) - $drift - $failed,
    $drift,
    $failed
));

if ($drift > 0 || $failed > 0) {
    fwrite(STDERR, "\n--- Issue template (file drift bug) ---\n");
    fwrite(STDERR, "Title: M5 vendor prelink drift: native rebuild hash mismatch ({$drift} drift, {$failed} failed)\n");
    fwrite(STDERR, "Category: bootstrap | vendor-prelink\n");
    fwrite(STDERR, "Packages:\n");
    foreach ($results as $audit) {
        if ('match' === $audit['status']) {
            continue;
        }
        fwrite(STDERR, sprintf(
            "  - %s (%s): committed=%s rebuilt=%s sources=%s blocker=%s\n",
            $audit['package'],
            $audit['status'],
            $audit['committed_hash'] ?? 'null',
            $audit['rebuilt_hash'] ?? 'null',
            $audit['sources_hash'] ?? 'null',
            $audit['blocker'] ?? 'n/a'
        ));
    }
    fwrite(STDERR, "Repro: BOOTSTRAP_M5_VENDOR_ALLOW_ZEND=0 ./script/bootstrap-vendor-native-rebuild-audit.sh\n");
    fwrite(STDERR, "Fix: rebuild committed sidecars from sources and refresh manifest:\n");
    fwrite(STDERR, "  php script/bootstrap-vendor-objects.php --compile\n");
    fwrite(STDERR, "Related: #8718 #1416 #1492\n");
    fwrite(STDERR, "--- end template ---\n");

    exit(1);
}

fwrite(STDOUT, "bootstrap-vendor-native-rebuild-audit: OK (committed sidecars match native rebuild)\n");

exit(0);
