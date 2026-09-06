#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Report freshness of the committed per-arch helper-unit cache (#15889).
 *
 * REPORT-ONLY for fingerprint drift by design: a stale committed unit is skipped
 * per fingerprint by HelperRuntimeCache and recompiled locally (NestedJIT), so
 * staleness can only cost build time, never correctness. Outcome gates
 * (cold-build-check, aot-smoke) catch real cache breakage. This check tells
 * maintainers when a refresh (`php script/emit-helper-runtime-object.php --prelink`)
 * is worth committing.
 *
 * Also verifies ELF e_machine of committed unit.o / common.o matches the arch
 * directory (#36391) — a host MCJIT fallback must not land x86_64 objects under
 * prelinked/helper-runtime/aarch64-linux/.
 *
 * Exit 0 always, unless --strict is passed (then broken / common / elf-mismatch /
 * UNITS_SHA256SUMS byte mismatch / absent x86 corpus -> exit 1). Fingerprint-stale
 * and pending gc_sections migrate (#36246) stay advisory under --strict so
 * release-readiness is not permanently red on a working monolithic corpus
 * (#36389 / #36401).
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
require __DIR__.'/helper-runtime-unit-checksums-lib.php';

$strict = in_array('--strict', $argv, true);
$allArches = in_array('--all-arches', $argv, true);

/**
 * @return array{arch: string, fresh: int, stale: int, broken: int, elf_mismatch: int, common_broken: int, gc_missing: int, checksum_broken: int, absent: bool}
 */
function check_helper_runtime_arch(string $root, string $archId): array
{
    putenv(CompileTarget::ENV.'='.$archId);
    $_ENV[CompileTarget::ENV] = $archId;
    $_SERVER[CompileTarget::ENV] = $archId;
    CompileTarget::resetCache();

    $arch = HelperRuntimeCache::archKey();
    $unitsDir = HelperRuntimeCache::prelinkedUnitsDir();
    $archDir = \dirname($unitsDir);
    $archManifestPath = $archDir.'/manifest.json';
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
        'checksum_broken' => 0,
        'absent' => false,
    ];

    if (!is_dir($unitsDir)) {
        $result['absent'] = true;

        return $result;
    }

    // Byte ledger (#36399): when UNITS_SHA256SUMS is present, every unit.o/unit.bc
    // must match. Missing ledger is advisory until the next intentional refresh
    // commits one (x86 --strict still fails on broken/common/elf).
    $checksumErrors = helper_runtime_verify_units_sha256sums($archDir, false);
    if ([] !== $checksumErrors) {
        $result['checksum_broken'] = \count($checksumErrors);
        foreach (array_slice($checksumErrors, 0, 8) as $err) {
            fwrite(STDOUT, "  HASH   {$err}\n");
        }
        if (\count($checksumErrors) > 8) {
            fwrite(STDOUT, '  HASH   … +'.(\count($checksumErrors) - 8)." more (#36399)\n");
        }
    } elseif (!is_readable(helper_runtime_units_sha256sums_path($archDir))) {
        fwrite(STDOUT, "  WARN   {$arch} missing UNITS_SHA256SUMS — php script/write-helper-runtime-unit-checksums.php (#36399)\n");
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
        // Advisory until an intentional --migrate-to-gc-sections corpus refresh lands
        // (#36246 / #36401). Requiring gc_sections before that migrate permanently reds
        // release-readiness on the working monolithic corpus (aot-smoke-safe without COMMON).
        if (!HelperRuntimeCache::prelinkedCorpusHasGcSections()) {
            ++$result['gc_missing'];
            fwrite(STDOUT, "  WARN   {$arch} gc_sections — committed unit.o uses monolithic .text (migrate: --force --prelink --migrate-to-gc-sections; #36246)\n");
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
            // --all-arches --strict: aarch64 must keep the curated seed (#36391).
            $exitFail = true;
        }
        continue;
    }

    $commonNote = HelperRuntimeCommon::commonObjectIsLinkable()
        ? ', common.o fresh'
        : (is_file(HelperRuntimeCommon::commonObjectPath()) ? ', common.o stale' : ', common.o absent');
    $gcNote = HelperRuntimeCache::prelinkedCorpusHasGcSections() ? ', gc_sections ok' : ', gc_sections pending (#36246)';
    $elfNote = $r['elf_mismatch'] > 0 ? ', '.$r['elf_mismatch'].' elf-mismatch' : ', elf ok';
    $hashNote = $r['checksum_broken'] > 0 ? ', '.$r['checksum_broken'].' hash-mismatch' : ', units hash ok';
    $needsRefresh = ($r['stale'] + $r['broken'] + $r['common_broken'] + $r['elf_mismatch'] + $r['checksum_broken']) > 0;
    fwrite(STDOUT, sprintf(
        "check-helper-runtime-prelink: %s — %d fresh, %d stale, %d broken%s%s%s%s%s\n",
        $r['arch'],
        $r['fresh'],
        $r['stale'],
        $r['broken'],
        $r['common_broken'] > 0 ? ', common broken' : $commonNote,
        $gcNote,
        $elfNote,
        $hashNote,
        $needsRefresh
            ? ' — refresh: PHP_COMPILER_TARGET='.$r['arch'].' php script/emit-helper-runtime-object.php --prelink'
            : ''
    ));

    if ($strict && ($r['broken'] + $r['common_broken'] + $r['elf_mismatch'] + $r['checksum_broken']) > 0) {
        $exitFail = true;
    }
    // Curated aarch64 seed must not shrink (#36391). Empty / short is not a pass.
    if ($allArches && CompileTarget::ID_AARCH64_LINUX === $r['arch'] && !$r['absent']) {
        $seedTotal = $r['fresh'] + $r['stale'] + $r['broken'];
        // Ratchet with script/seed-aarch64-helper-runtime.sh SEED_UNITS (VM_*+lib_VM_*+ext/standard tiers).
        $minSeed = 122;
        if ($seedTotal < $minSeed) {
            fwrite(STDOUT, sprintf(
                "check-helper-runtime-prelink: aarch64-linux seed too small (%d < %d) — run ./script/seed-aarch64-helper-runtime.sh (#36391)\n",
                $seedTotal,
                $minSeed
            ));
            if ($strict) {
                $exitFail = true;
            }
        }
    }
    // Freshness (stale) remains report-only even under --strict (#36389): NestedJIT
    // covers fingerprint-stale units; cold-build / aot-smoke are the outcome gates.
    // gc_missing is advisory: monolithic corpus is still the shipped shape until migrate (#36246).
    // x86 --strict still fails on broken / common / elf / absent (above).
    if ($strict && CompileTarget::ID_X86_64_LINUX === $r['arch'] && $r['stale'] > 0) {
        fwrite(STDOUT, "check-helper-runtime-prelink: NOTE — {$r['stale']} fingerprint-stale unit(s) are advisory under --strict (NestedJIT covers; refresh when convenient)\n");
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
