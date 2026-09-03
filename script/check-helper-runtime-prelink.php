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
 * Also verifies ELF e_machine of committed unit.o / common.o matches the arch
 * directory (#36391) — a host MCJIT fallback must not land x86_64 objects under
 * prelinked/helper-runtime/aarch64-linux/.
 *
 * Exit 0 always, unless --strict is passed (then stale/absent/elf-mismatch -> exit 1).
 *
 * Usage:
 *   php script/check-helper-runtime-prelink.php                 # report (current TARGET)
 *   php script/check-helper-runtime-prelink.php --strict        # gate
 *   php script/check-helper-runtime-prelink.php --all-arches    # every known Linux id
 */

use PHPCompiler\AOT\CompileTarget;
use PHPCompiler\AOT\HelperRuntimeCache;
use PHPCompiler\AOT\HelperRuntimeCommon;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

$strict = in_array('--strict', $argv, true);
$allArches = in_array('--all-arches', $argv, true);

/**
 * @return array{arch: string, fresh: int, stale: int, broken: int, elf_mismatch: int, common_broken: int, gc_missing: int, absent: bool}
 */
function check_helper_runtime_arch(string $root, string $archId): array
{
    putenv(CompileTarget::ENV.'='.$archId);
    $_ENV[CompileTarget::ENV] = $archId;
    $_SERVER[CompileTarget::ENV] = $archId;
    CompileTarget::resetCache();

    $arch = HelperRuntimeCache::archKey();
    $unitsDir = HelperRuntimeCache::prelinkedUnitsDir();
    $archManifestPath = \dirname($unitsDir).'/manifest.json';
    $liveCore = HelperRuntimeCache::coreFingerprint();
    $target = CompileTarget::resolve($archId);

    $result = [
        'arch' => $arch,
        'fresh' => 0,
        'stale' => 0,
        'broken' => 0,
        'elf_mismatch' => 0,
        'common_broken' => 0,
        'gc_missing' => 0,
        'absent' => false,
    ];

    if (!is_dir($unitsDir)) {
        $result['absent'] = true;

        return $result;
    }

    if (is_file($archManifestPath)) {
        $archManifest = json_decode((string) file_get_contents($archManifestPath), true);
        $committedCore = \is_array($archManifest) ? (string) ($archManifest['core_fingerprint'] ?? '') : '';
        if ('' !== $committedCore && !HelperRuntimeCache::coreFingerprintMatches($committedCore)) {
            fwrite(STDOUT, sprintf(
                "check-helper-runtime-prelink: %s core_fingerprint mismatch — committed %s vs live %s (#24302)\n",
                $arch,
                $committedCore,
                $liveCore
            ));
        }
        $expectedCommonSha = \is_array($archManifest) ? (string) ($archManifest['common_object_sha256'] ?? '') : '';
        if ('' !== $expectedCommonSha) {
            $commonPath = HelperRuntimeCommon::commonObjectPath();
            if (!is_file($commonPath)) {
                ++$result['common_broken'];
                fwrite(STDOUT, "  BROKEN {$arch}/common.o (manifest expects shared runtime prologue)\n");
            } elseif (!HelperRuntimeCommon::commonObjectIsLinkable()) {
                ++$result['common_broken'];
                fwrite(STDOUT, "  STALE  {$arch}/common.o (sha256 or core_fingerprint mismatch)\n");
            }
        }
        if (!HelperRuntimeCache::prelinkedCorpusHasGcSections()) {
            ++$result['gc_missing'];
            fwrite(STDOUT, "  STALE  {$arch} gc_sections — committed unit.o uses monolithic .text (refresh with --force --prelink; #36246)\n");
        }
    }

    $commonPath = HelperRuntimeCommon::commonObjectPath();
    if (is_file($commonPath) && null !== $target->elfMachine()) {
        try {
            $target->assertObjectMatchesTarget($commonPath);
        } catch (\RuntimeException $e) {
            ++$result['elf_mismatch'];
            fwrite(STDOUT, '  ELF    '.$e->getMessage()."\n");
        }
    }

    $manifests = glob($unitsDir.'/*/manifest.json') ?: [];
    if ([] === $manifests) {
        $result['absent'] = true;

        return $result;
    }

    foreach ($manifests as $manifestPath) {
        $unitDir = dirname($manifestPath);
        $slug = basename($unitDir);
        $manifest = HelperRuntimeCache::unitManifest($slug, $unitDir);
        if (null === $manifest || !is_file($unitDir.'/unit.o') || !is_file($unitDir.'/unit.bc')) {
            ++$result['broken'];
            fwrite(STDOUT, "  BROKEN {$arch}/{$slug} (incomplete unit)\n");

            continue;
        }
        if (null !== $target->elfMachine()) {
            try {
                $target->assertObjectMatchesTarget($unitDir.'/unit.o');
            } catch (\RuntimeException $e) {
                ++$result['elf_mismatch'];
                fwrite(STDOUT, '  ELF    '.$e->getMessage()."\n");
            }
        }
        $sourceAbs = HelperRuntimeCache::resolveUnitSource($root, (string) $manifest['unit']);
        if (null === $sourceAbs) {
            ++$result['stale'];
            fwrite(STDOUT, "  STALE  {$arch}/{$slug} (source gone: {$manifest['unit']})\n");

            continue;
        }
        if (!HelperRuntimeCache::manifestFingerprintMatches($manifest, $sourceAbs)) {
            ++$result['stale'];
            fwrite(STDOUT, "  STALE  {$arch}/{$slug} ({$manifest['unit']})\n");

            continue;
        }
        ++$result['fresh'];
    }

    return $result;
}

$archIds = $allArches
    ? [CompileTarget::ID_X86_64_LINUX, CompileTarget::ID_AARCH64_LINUX]
    : [HelperRuntimeCache::archKey()];

$savedTarget = \PHPCompiler\Config::getenv(CompileTarget::ENV);
$exitFail = false;

foreach ($archIds as $archId) {
    $r = check_helper_runtime_arch($root, $archId);
    if ($r['absent']) {
        fwrite(STDOUT, "check-helper-runtime-prelink: {$r['arch']} — 0 units (no committed cache; cold builds compile helpers locally)\n");
        if ($strict && CompileTarget::ID_X86_64_LINUX === $r['arch']) {
            // Host x86 corpus is required; aarch64 may still be seed-only / empty during rollout (#36391).
            $exitFail = true;
        } elseif ($strict && $allArches && CompileTarget::ID_AARCH64_LINUX === $r['arch']) {
            // --all-arches --strict: aarch64 must have at least one published unit once seeded.
            $exitFail = true;
        }
        continue;
    }

    $commonNote = HelperRuntimeCommon::commonObjectIsLinkable()
        ? ', common.o fresh'
        : (is_file(HelperRuntimeCommon::commonObjectPath()) ? ', common.o stale' : ', common.o absent');
    $gcNote = HelperRuntimeCache::prelinkedCorpusHasGcSections() ? ', gc_sections ok' : ', gc_sections stale';
    $elfNote = $r['elf_mismatch'] > 0 ? ', '.$r['elf_mismatch'].' elf-mismatch' : ', elf ok';
    fwrite(STDOUT, sprintf(
        "check-helper-runtime-prelink: %s — %d fresh, %d stale, %d broken%s%s%s%s\n",
        $r['arch'],
        $r['fresh'],
        $r['stale'],
        $r['broken'],
        $r['common_broken'] > 0 ? ', common broken' : $commonNote,
        $r['gc_missing'] > 0 ? '' : $gcNote,
        $elfNote,
        ($r['stale'] + $r['broken'] + $r['common_broken'] + $r['gc_missing'] + $r['elf_mismatch']) > 0
            ? ' — refresh: PHP_COMPILER_TARGET='.$r['arch'].' php script/emit-helper-runtime-object.php --force --prelink'
            : ''
    ));

    if ($strict && ($r['broken'] + $r['common_broken'] + $r['elf_mismatch']) > 0) {
        $exitFail = true;
    }
    // Freshness (stale) remains report-only even under --strict for non-x86 seed corpora —
    // aarch64 seed units will look "stale" vs live core until a full refresh; ELF/broken gate.
    if ($strict && CompileTarget::ID_X86_64_LINUX === $r['arch']
        && ($r['stale'] + $r['gc_missing']) > 0) {
        $exitFail = true;
    }
}

// Restore caller TARGET env.
if (false === $savedTarget || null === $savedTarget || '' === $savedTarget) {
    putenv(CompileTarget::ENV);
    unset($_ENV[CompileTarget::ENV], $_SERVER[CompileTarget::ENV]);
} else {
    putenv(CompileTarget::ENV.'='.$savedTarget);
    $_ENV[CompileTarget::ENV] = $savedTarget;
    $_SERVER[CompileTarget::ENV] = $savedTarget;
}
CompileTarget::resetCache();

exit($exitFail ? 1 : 0);
