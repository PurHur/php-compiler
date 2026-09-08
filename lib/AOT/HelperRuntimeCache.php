<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

use PHPCompiler\JIT\Context;
use PHPCompiler\Config;

require_once __DIR__.'/HelperRuntimeFingerprint.php';
require_once __DIR__.'/HelperRuntimeLink.php';
require_once __DIR__.'/HelperRuntimeBind.php';
require_once __DIR__.'/HelperRuntimeIndex.php';

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
 * Fingerprint / identity / unit-deps hashing lives in {@see HelperRuntimeFingerprint};
 * link selection + unit.o safety gates live in {@see HelperRuntimeLink};
 * bitcode bind / type localize / lifecycle live in {@see HelperRuntimeBind};
 * unit manifest / helperIndex scan lives in {@see HelperRuntimeIndex}
 * (#36387 / #36403 size-budget ratchet).
 */
final class HelperRuntimeCache
{
    private const ENV_FLAG = 'PHP_COMPILER_HELPER_RUNTIME_O';

    private const ENV_DIR = 'PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR';

    /** Guard so the emitter itself never consumes the cache. */
    private const ENV_EMITTING = 'PHP_COMPILER_HELPER_RUNTIME_EMITTING';

    /** Marker for a warmed cache at a given core fingerprint (#15889). */
    private const CORE_MARKER_PREFIX = 'core-';

    public static function enabled(): bool
    {
        if ('1' === getenv(self::ENV_EMITTING)) {
            return false;
        }
        $flag = getenv(self::ENV_FLAG);

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    public static function cacheDir(): string
    {
        $dir = getenv(self::ENV_DIR);
        if (is_string($dir) && '' !== $dir) {
            return rtrim($dir, '/');
        }

        return \dirname(__DIR__, 2).'/build/helper-runtime-cache';
    }

    private static function coreMarkerPath(): string
    {
        return self::cacheDir().'/'.self::CORE_MARKER_PREFIX.self::coreFingerprint().'.ok';
    }

    /**
     * Best-effort warmup for user-script AOT builds (#15889).
     *
     * When the cache is enabled but cold, run the incremental helper-unit emitter once per core
     * fingerprint. Subsequent builds should be cache hits with no nested helper lowering.
     */
    public static function warmForUserAotBuild(): void
    {
        if (!self::enabled()) {
            return;
        }
        // Only for user-script AOT builds; bootstrap/self-host pipelines own their own emit ladders.
        $user = Config::getenv('PHP_COMPILER_AOT_USER_SCRIPT');
        if ('1' !== $user && 'true' !== strtolower((string) $user)) {
            return;
        }
        $marker = self::coreMarkerPath();
        if (is_file($marker)) {
            return;
        }
        // The marker lives under build/helper-runtime-cache, which is gitignored — so a CLEAN
        // CHECKOUT never has it and every first user AOT build re-emitted the whole corpus, even
        // when the committed per-arch cache was present and current. Measured: ~517s to compile
        // `<?php echo "hi\n";` on a fresh tree, 5s once warm (#24302).
        //
        // helperIndex() already skips stale units per fingerprint and NestedJIT fills gaps, so a
        // patches/ or composer.lock change that drifts core_fingerprint must NOT launch a 410-unit
        // emit from `phpc build` / aot-smoke (120s timeout, rc=124). Presence of committed unit.o
        // files is enough to skip the corpus warmup (#32122). Maintainers refresh with
        // emit-helper-runtime-object.php --prelink or --refresh-global-fingerprints.
        if (self::committedCacheHasUnits()) {
            @mkdir(\dirname($marker), 0755, true);
            @file_put_contents($marker, 'ok (committed units present; skip corpus warmup) '.gmdate('c')."\n");

            return;
        }

        $root = \dirname(__DIR__, 2);
        $script = $root.'/script/emit-helper-runtime-object.php';
        if (!is_file($script)) {
            return;
        }
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script);
        $rc = self::runWarmupCommand($cmd);
        if (0 === $rc) {
            @mkdir(\dirname($marker), 0755, true);
            @file_put_contents($marker, 'ok '.gmdate('c')."\n");
            // Any new units should be visible immediately.
            HelperRuntimeIndex::invalidate();
        }
    }

    /**
     * Committed per-arch cache has objects we can skip whole-corpus warmup for (#24302 / #32122).
     *
     * Core-fingerprint drift is not a reason to emit 410 units from a user-script compile.
     * helperIndex() still skips stale units per fingerprint; NestedJIT fills gaps. Only a missing
     * or empty committed tree (wrong arch / incomplete clone) falls through to warmup.
     */
    private static function committedCacheHasUnits(): bool
    {
        $unitsDir = self::prelinkedUnitsDir();
        if (!is_dir($unitsDir)) {
            return false;
        }
        $entries = @scandir($unitsDir);
        if (false === $entries) {
            return false;
        }
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $dir = $unitsDir.'/'.$entry;
            if (is_dir($dir) && is_file($dir.'/unit.o') && is_file($dir.'/unit.bc')) {
                return true;
            }
        }

        return false;
    }

    private static function runWarmupCommand(string $command): int
    {
        // Prefer the in-repo polyfill when available (self-host safe).
        if (\function_exists('phpc_run_command')) {
            $out = \phpc_run_command($command);
            if (\is_array($out)) {
                return (int) ($out['code'] ?? 127);
            }
        }
        $ignored = [];
        $rc = 127;
        @exec($command.' 2>/dev/null', $ignored, $rc);

        return (int) $rc;
    }

    public static function unitsDir(): string
    {
        return self::cacheDir().'/units';
    }

    public static function unitDir(string $slug): string
    {
        return self::unitsDir().'/'.$slug;
    }

    public static function slugFor(string $unitPath): string
    {
        return (string) preg_replace('#[^A-Za-z0-9]+#', '_', trim($unitPath, '/'));
    }

    /** Architecture key for shareable prelinked unit objects, e.g. "x86_64-linux" (#36391). */
    public static function archKey(): string
    {
        return CompileTarget::current()->id();
    }

    /** Committed per-arch unit cache: prelinked/helper-runtime/<arch>/units. */
    public static function prelinkedUnitsDir(): string
    {
        return CompileTarget::current()->helperRuntimeArchDir(\dirname(__DIR__, 2)).'/units';
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

    public static function markEmitting(): void
    {
        putenv(self::ENV_EMITTING.'=1');
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
