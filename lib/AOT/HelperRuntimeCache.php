<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

use PHPCompiler\JIT\Context;

require_once __DIR__.'/HelperRuntimePaths.php';
require_once __DIR__.'/HelperRuntimeFingerprintUnit.php';
require_once __DIR__.'/HelperRuntimeFingerprint.php';
require_once __DIR__.'/HelperRuntimeLink.php';
require_once __DIR__.'/HelperRuntimeBind.php';
require_once __DIR__.'/HelperRuntimeIndex.php';
require_once __DIR__.'/HelperRuntimeWarm.php';

/**
 * Incremental split-compilation cache for php-in-PHP JIT helpers (#15889).
 *
 * Each helper unit is its own translation unit, cached independently:
 *
 *   build/helper-runtime-cache/units/<slug>/
 *     unit.bc        — bitcode; per-script builds read exact function types
 *     unit.o         — object the Linker merges at the end
 *     manifest.json  — {fingerprint, unit, deps?, helpers: logical → symbol}
 *     failed.json    — {fingerprint, rc} when the unit's lowering crashes;
 *                      re-attempted only when its fingerprint changes
 *
 * Freshness is PER UNIT (#23458):
 *
 *   v2 (manifest has deps[]): sha256(global + unit source + each dep's content)
 *   v1 (legacy, no deps):     sha256(legacy lowering core + unit source)
 *
 * Global inputs ({@see coreFingerprint}) are composer.lock, patches, LLVM
 * library identity (#24381), and runtime struct layout sources
 * ({@see runtimeLayoutFingerprintPaths}) — content hash of libLLVM-9.so.1,
 * not the install path string, so host `.llvm` and Docker `/opt/llvm9` with
 * the same bytes share a fingerprint. Editing lib/JIT.php no longer
 * invalidates the whole corpus; editing a layout `.pre` (e.g. __value__ ABI
 * #36214) does. Emit records the NestedJIT closure in deps[]; editing one
 * reached lowering invalidates only units that listed it. Legacy manifests
 * keep the old JIT-core key until re-emitted so the committed prelinked tier
 * stays usable.
 *
 * Opt-in: PHP_COMPILER_HELPER_RUNTIME_O=1.
 *
 * Path / env / arch dirs live in {@see HelperRuntimePaths};
 * fingerprint / identity lives in {@see HelperRuntimeFingerprint};
 * per-unit deps / manifest v2 hashing lives in {@see HelperRuntimeFingerprintUnit};
 * link selection + unit.o safety gates live in {@see HelperRuntimeLink};
 * bitcode bind / type localize / lifecycle live in {@see HelperRuntimeBind};
 * unit manifest / helperIndex scan lives in {@see HelperRuntimeIndex};
 * user-AOT warm / committed-tier skip lives in {@see HelperRuntimeWarm}
 * (#36387 / #36403 size-budget ratchet).
 */
final class HelperRuntimeCache
{
    /** @see HelperRuntimePaths::enabled() */
    public static function enabled(): bool
    {
        return HelperRuntimePaths::enabled();
    }

    /** @see HelperRuntimePaths::cacheDir() */
    public static function cacheDir(): string
    {
        return HelperRuntimePaths::cacheDir();
    }

    /**
     * Best-effort warmup for user-script AOT builds (#15889).
     *
     * @see HelperRuntimeWarm::warmForUserAotBuild()
     */
    public static function warmForUserAotBuild(): void
    {
        HelperRuntimeWarm::warmForUserAotBuild();
    }

    /** @see HelperRuntimePaths::unitsDir() */
    public static function unitsDir(): string
    {
        return HelperRuntimePaths::unitsDir();
    }

    /** @see HelperRuntimePaths::unitDir() */
    public static function unitDir(string $slug): string
    {
        return HelperRuntimePaths::unitDir($slug);
    }

    /** @see HelperRuntimePaths::slugFor() */
    public static function slugFor(string $unitPath): string
    {
        return HelperRuntimePaths::slugFor($unitPath);
    }

    /**
     * Architecture key for shareable prelinked unit objects, e.g. "x86_64-linux" (#36391).
     *
     * @see HelperRuntimePaths::archKey()
     */
    public static function archKey(): string
    {
        return HelperRuntimePaths::archKey();
    }

    /**
     * Committed per-arch unit cache: prelinked/helper-runtime/<arch>/units.
     *
     * @see HelperRuntimePaths::prelinkedUnitsDir()
     */
    public static function prelinkedUnitsDir(): string
    {
        return HelperRuntimePaths::prelinkedUnitsDir();
    }

    /**
     * Global inputs (#23458 / #24381): composer.lock, patches, LLVM library,
     * runtime struct layout `.pre` sources (#36214).
     *
     * @see HelperRuntimeFingerprint::coreFingerprint()
     */
    public static function coreFingerprint(): string
    {
        return HelperRuntimeFingerprint::coreFingerprint();
    }

    /**
     * Digest of linkable helper-runtime units for MCJIT/AOT compile-cache keys (#36199).
     *
     * @see HelperRuntimeFingerprint::cacheKeySegment()
     */
    public static function cacheKeySegment(): string
    {
        return HelperRuntimeFingerprint::cacheKeySegment();
    }

    /** @see HelperRuntimeFingerprint::legacyLoweringFingerprint() */
    public static function legacyLoweringFingerprint(): string
    {
        return HelperRuntimeFingerprint::legacyLoweringFingerprint();
    }

    /** @see HelperRuntimeFingerprint::llvmIdentityToken() */
    public static function llvmIdentityToken(): string
    {
        return HelperRuntimeFingerprint::llvmIdentityToken();
    }

    /**
     * @return list<string>
     *
     * @see HelperRuntimeFingerprint::equivalentCoreFingerprints()
     */
    public static function equivalentCoreFingerprints(): array
    {
        return HelperRuntimeFingerprint::equivalentCoreFingerprints();
    }

    /** @see HelperRuntimeFingerprint::coreFingerprintMatches() */
    public static function coreFingerprintMatches(string $candidate): bool
    {
        return HelperRuntimeFingerprint::coreFingerprintMatches($candidate);
    }

    /**
     * @return list<string> repo-root-relative paths
     *
     * @see HelperRuntimeFingerprint::runtimeLayoutFingerprintPaths()
     */
    public static function runtimeLayoutFingerprintPaths(): array
    {
        return HelperRuntimeFingerprint::runtimeLayoutFingerprintPaths();
    }

    /**
     * Repo-root relative path (/lib/… or /ext/…) for an absolute file, or null.
     *
     * @see HelperRuntimeFingerprint::repoRelPath()
     */
    public static function repoRelPath(string $absPath): ?string
    {
        return HelperRuntimeFingerprint::repoRelPath($absPath);
    }

    /**
     * @param list<string> $compiledAbsPaths from Context::listJitCompiledIncludePaths()
     *
     * @return list<string> sorted unique repo-relative paths
     *
     * @see HelperRuntimeFingerprint::dependencyRelPathsForEmit()
     */
    public static function dependencyRelPathsForEmit(string $unitSourceAbsPath, array $compiledAbsPaths): array
    {
        return HelperRuntimeFingerprint::dependencyRelPathsForEmit($unitSourceAbsPath, $compiledAbsPaths);
    }

    /**
     * @param list<string>|null $depsRelPaths repo-relative paths; null = v2 with unit-only + extras
     *
     * @see HelperRuntimeFingerprint::unitFingerprint()
     */
    public static function unitFingerprint(string $unitSourceAbsPath, ?array $depsRelPaths = null): string
    {
        return HelperRuntimeFingerprint::unitFingerprint($unitSourceAbsPath, $depsRelPaths);
    }

    /**
     * @param array{fingerprint?: string, unit?: string, deps?: list<string>|mixed} $manifest
     *
     * @see HelperRuntimeFingerprint::expectedFingerprintForManifest()
     */
    public static function expectedFingerprintForManifest(array $manifest, string $unitSourceAbsPath): string
    {
        return HelperRuntimeFingerprint::expectedFingerprintForManifest($manifest, $unitSourceAbsPath);
    }

    /** @see HelperRuntimeFingerprint::manifestFingerprintMatches() */
    public static function manifestFingerprintMatches(array $manifest, string $unitSourceAbsPath): bool
    {
        return HelperRuntimeFingerprint::manifestFingerprintMatches($manifest, $unitSourceAbsPath);
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return array<string, mixed>|null
     *
     * @see HelperRuntimeFingerprint::migrateManifestToV2()
     */
    public static function migrateManifestToV2(array $manifest, string $unitSourceAbsPath): ?array
    {
        return HelperRuntimeFingerprint::migrateManifestToV2($manifest, $unitSourceAbsPath);
    }

    /**
     * @param list<string> $depsRelPaths
     *
     * @see HelperRuntimeFingerprint::fingerprintV2()
     */
    public static function fingerprintV2(string $unitSourceAbsPath, array $depsRelPaths): string
    {
        return HelperRuntimeFingerprint::fingerprintV2($unitSourceAbsPath, $depsRelPaths);
    }

    /**
     * @param list<string> $depsRelPaths
     *
     * @see HelperRuntimeFingerprint::fingerprintV2WithCore()
     */
    public static function fingerprintV2WithCore(string $unitSourceAbsPath, array $depsRelPaths, string $core): string
    {
        return HelperRuntimeFingerprint::fingerprintV2WithCore($unitSourceAbsPath, $depsRelPaths, $core);
    }


    /**
     * @see HelperRuntimeIndex::unitManifest()
     *
     * @return array{fingerprint: string, unit: string, helpers: array<string,string>}|null
     */
    public static function unitManifest(string $slug, ?string $unitDir = null): ?array
    {
        return HelperRuntimeIndex::unitManifest($slug, $unitDir);
    }

    /**
     * @see HelperRuntimeIndex::unitFailure()
     *
     * @return array{fingerprint: string, rc: int}|null persisted crash marker
     */
    public static function unitFailure(string $slug): ?array
    {
        return HelperRuntimeIndex::unitFailure($slug);
    }

    /**
     * logical(lower) → {symbol, dir} across all FRESH unit manifests.
     *
     * @see HelperRuntimeIndex::helperIndex()
     *
     * @return array<string, array{symbol: string, dir: string}>
     */
    public static function helperIndex(): array
    {
        return HelperRuntimeIndex::helperIndex();
    }

    /** @see HelperRuntimeIndex::resolveUnitSource() */
    public static function resolveUnitSource(string $root, string $unitPath): ?string
    {
        return HelperRuntimeIndex::resolveUnitSource($root, $unitPath);
    }

    /**
     * Bind every cached helper among $logicalNames into $context->functions as
     * an extern declaration with the exact type from the unit's bitcode.
     *
     * @see HelperRuntimeBind::tryProvide()
     *
     * @param list<string> $logicalNames
     */
    public static function tryProvide(Context $context, array $logicalNames): bool
    {
        return HelperRuntimeBind::tryProvide($context, $logicalNames);
    }

    /**
     * Declare an extern from a producer chunk's bitcode (#36155 Phase C).
     *
     * @see HelperRuntimeBind::declareExternFromBitcode()
     */
    public static function declareExternFromBitcode(Context $context, string $symbol, string $bitcodePath): ?object
    {
        return HelperRuntimeBind::declareExternFromBitcode($context, $symbol, $bitcodePath);
    }

    /**
     * Linker hook: unit objects whose helpers were bound in this build.
     *
     * @see HelperRuntimeLink::linkObjects()
     *
     * @return list<string>
     */
    public static function linkObjects(): array
    {
        return HelperRuntimeLink::linkObjects();
    }

    /**
     * Basenames of helper units currently selected for link (#36387 object mid-tier).
     *
     * @see HelperRuntimeLink::usedUnitSlugs()
     *
     * @return list<string>
     */
    public static function usedUnitSlugs(): array
    {
        return HelperRuntimeLink::usedUnitSlugs();
    }

    /**
     * Rebuild used units from cached slugs so {@see linkObjects()} works without
     * a fresh lowering pass (#36387 mid-tier `.o` restore).
     *
     * @see HelperRuntimeLink::adoptUnitSlugsForLink()
     *
     * @param list<string> $slugs
     */
    public static function adoptUnitSlugsForLink(array $slugs): void
    {
        HelperRuntimeLink::adoptUnitSlugsForLink($slugs);
    }

    /**
     * Local tier first, then committed prelinked tier (#36387).
     *
     * @see HelperRuntimeLink::resolveLinkableUnitDir()
     */
    public static function resolveLinkableUnitDir(string $slug): ?string
    {
        return HelperRuntimeLink::resolveLinkableUnitDir($slug);
    }

    /** @see HelperRuntimePaths::markEmitting() */
    public static function markEmitting(): void
    {
        HelperRuntimePaths::markEmitting();
    }

    /**
     * A zero-byte unit.o can exist when emit was interrupted; it must not shadow the
     * committed prelinked tier or link as an empty object (undefined helper symbols, #6229).
     *
     * @see HelperRuntimeLink::unitObjectIsLinkable()
     */
    public static function unitObjectIsLinkable(string $unitDir): bool
    {
        return HelperRuntimeLink::unitObjectIsLinkable($unitDir);
    }

    /**
     * Whether a unit.o may participate in an AOT link under HELPER_RUNTIME_O=1.
     *
     * @see HelperRuntimeLink::unitObjectIsSafeToLink()
     */
    public static function unitObjectIsSafeToLink(string $unitDir): bool
    {
        return HelperRuntimeLink::unitObjectIsSafeToLink($unitDir);
    }

    /**
     * Why a built unit.o must not be published into the committed prelinked tree.
     *
     * @see HelperRuntimeLink::refusePrelinkGcMixReason()
     *
     * @return string|null null when publish is allowed
     */
    public static function refusePrelinkGcMixReason(string $unitObjectPath, bool $migrateToGcSections = false): ?string
    {
        return HelperRuntimeLink::refusePrelinkGcMixReason($unitObjectPath, $migrateToGcSections);
    }

    /**
     * True when $objectPath carries AotGcSections per-function ELF sections (.text.<symbol>).
     *
     * @see HelperRuntimeLink::unitObjectHasPerFunctionSections()
     */
    public static function unitObjectHasPerFunctionSections(string $objectPath): bool
    {
        return HelperRuntimeLink::unitObjectHasPerFunctionSections($objectPath);
    }

    /**
     * Committed prelinked corpus was emitted with AotGcSections (per-function .text.* sections).
     *
     * @see HelperRuntimeLink::prelinkedCorpusHasGcSections()
     */
    public static function prelinkedCorpusHasGcSections(): bool
    {
        return HelperRuntimeLink::prelinkedCorpusHasGcSections();
    }

}
