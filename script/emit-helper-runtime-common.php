#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Emit the shared helper-runtime prologue once per arch (#36198 part B / #36246).
 *
 * Publishes prelinked/helper-runtime/<arch>/common.o from the anchor unit's committed
 * unit.o (runtime symbol superset shared across the corpus). Fresh re-emit via
 * emit-helper-runtime-object.php --unit=… when unit lowering is green again; linking
 * common.o before unit objects lets -z muldefs + --gc-sections drop duplicate runtime
 * bodies once units carry per-function ELF sections (AotGcSections).
 *
 * Usage (pinned env):
 *   ./script/docker-exec.sh -- bash -lc 'php script/emit-helper-runtime-common.php --from-prelinked'
 */

use PHPCompiler\AOT\HelperRuntimeCommon;
use PHPCompiler\AOT\HelperRuntimeCache;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

const ANCHOR_UNIT = '/ext/ctype/CtypeJitHelper.php';

$fromPrelinked = in_array('--from-prelinked', $argv, true);

$objectPath = HelperRuntimeCommon::commonObjectPath();
$bitcodePath = HelperRuntimeCommon::commonBitcodePath();
$outDir = dirname($objectPath);
if (!is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
    fwrite(STDERR, "helper-runtime-common-emit: cannot create {$outDir}\n");
    exit(1);
}

$slug = HelperRuntimeCache::slugFor(ANCHOR_UNIT);
$unitDir = $fromPrelinked
    ? HelperRuntimeCache::prelinkedUnitsDir().'/'.$slug
    : HelperRuntimeCache::unitDir($slug);
$unitObject = $unitDir.'/unit.o';
$unitBitcode = $unitDir.'/unit.bc';

if (!$fromPrelinked) {
    $unitEmitter = $root.'/script/emit-helper-runtime-object.php';
    if (!is_file($unitEmitter)) {
        fwrite(STDERR, "helper-runtime-common-emit: missing {$unitEmitter}\n");
        exit(1);
    }
    fwrite(STDOUT, 'helper-runtime-common-emit: anchor unit '.ANCHOR_UNIT." (slug={$slug})…\n");
    $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($unitEmitter)
        .' --unit='.escapeshellarg(ANCHOR_UNIT);
    passthru($cmd, $rc);
    if (0 !== $rc) {
        fwrite(STDERR, "helper-runtime-common-emit: anchor emit failed (rc={$rc}); retry with --from-prelinked\n");
        exit($rc);
    }
} else {
    fwrite(STDOUT, "helper-runtime-common-emit: publishing from committed anchor {$slug}…\n");
}

if (!HelperRuntimeCache::unitObjectIsLinkable($unitDir) || !is_file($unitBitcode)) {
    fwrite(STDERR, "helper-runtime-common-emit: anchor unit incomplete under {$unitDir}\n");
    exit(1);
}

if (!copy($unitObject, $objectPath) || !copy($unitBitcode, $bitcodePath)) {
    fwrite(STDERR, "helper-runtime-common-emit: failed to publish common.o\n");
    exit(1);
}

HelperRuntimeCommon::publishManifestMetadata($objectPath, $bitcodePath);

fwrite(STDOUT, sprintf(
    "helper-runtime-common-emit: OK %s (%d bytes), bitcode %d bytes, core=%s\n",
    $objectPath,
    filesize($objectPath),
    filesize($bitcodePath),
    HelperRuntimeCache::coreFingerprint()
));
