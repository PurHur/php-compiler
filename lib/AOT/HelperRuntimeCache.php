<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

use PHPCompiler\JIT\Context;

/**
 * Incremental split-compilation cache for php-in-PHP JIT helpers (#15889).
 *
 * Each helper unit is its own translation unit, cached independently:
 *
 *   build/helper-runtime-cache/units/<slug>/
 *     unit.bc        — bitcode; per-script builds read exact function types
 *     unit.o         — object the Linker merges at the end
 *     manifest.json  — {fingerprint, unit, helpers: logical → symbol}
 *     failed.json    — {fingerprint, rc} when the unit's lowering crashes;
 *                      re-attempted only when its fingerprint changes
 *
 * Freshness is PER UNIT: sha256(core fingerprint + unit source content).
 * The core fingerprint covers only the lowering machinery (JIT core,
 * composer.lock, LLVM path) — editing one helper re-emits one unit, a crash
 * is remembered per unit, and nothing else recompiles.
 *
 * Known approximation: a unit module may embed dependency helpers it pulled
 * in during nested lowering; an edit to a dependency does not invalidate the
 * embedding unit's fingerprint. `script/emit-helper-runtime-object.php
 * --force` re-emits everything; bumping the core (lib/JIT.php etc.) also
 * invalidates all units.
 *
 * Opt-in: PHP_COMPILER_HELPER_RUNTIME_O=1.
 */
final class HelperRuntimeCache
{
    private const ENV_FLAG = 'PHP_COMPILER_HELPER_RUNTIME_O';

    private const ENV_DIR = 'PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR';

    /** Guard so the emitter itself never consumes the cache. */
    private const ENV_EMITTING = 'PHP_COMPILER_HELPER_RUNTIME_EMITTING';

    /** Marker for a warmed cache at a given core fingerprint (#15889). */
    private const CORE_MARKER_PREFIX = 'core-';

    /** @var array<string, array{symbol: string, dir: string}>|null logical(lower) → binding */
    private static ?array $helperIndex = null;

    /** @var array<string, object> unit dir → parsed bitcode module (kept alive: types are shared) */
    private static array $parsedUnits = [];

    /** @var array<string, true> unit dir → merged at link time */
    private static array $usedUnits = [];

    private static bool $loggedHit = false;

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
        $user = getenv('PHP_COMPILER_AOT_USER_SCRIPT');
        if ('1' !== $user && 'true' !== strtolower((string) $user)) {
            return;
        }
        $marker = self::coreMarkerPath();
        if (is_file($marker)) {
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
            self::$helperIndex = null;
        }
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

    /**
     * Lowering-machinery fingerprint: deliberately narrow so single-helper
     * edits do not invalidate the whole cache (#15889 incrementality).
     *
     * Content hashes, not mtime:size — fingerprints must agree across clones
     * and architectures so committed prelinked units are shareable (#15889).
     * patches/ is included: vendor patches change lowering behaviour but
     * composer.lock cannot see them.
     */
    public static function coreFingerprint(): string
    {
        static $core = null;
        if (null !== $core) {
            return $core;
        }
        $root = \dirname(__DIR__, 2);
        $parts = [(string) getenv('PHP_COMPILER_LLVM_PATH')];
        foreach ([
            $root.'/composer.lock',
            $root.'/lib/JIT.php',
            $root.'/lib/JIT/Context.php',
            $root.'/lib/Runtime.php',
            $root.'/lib/JIT/JitVmHelperLink.php',
            $root.'/script/apply-patches.sh',
        ] as $file) {
            $parts[] = substr($file, \strlen($root)).':'.@hash_file('sha256', $file);
        }
        $patchFiles = glob($root.'/patches/*.patch') ?: [];
        sort($patchFiles, SORT_STRING);
        foreach ($patchFiles as $patch) {
            $parts[] = substr($patch, \strlen($root)).':'.@hash_file('sha256', $patch);
        }

        return $core = substr(hash('sha256', implode("\n", $parts)), 0, 20);
    }

    /** Architecture key for shareable prelinked unit objects, e.g. "x86_64-linux". */
    public static function archKey(): string
    {
        return php_uname('m').'-'.strtolower(php_uname('s'));
    }

    /** Committed per-arch unit cache: prelinked/helper-runtime/<arch>/units. */
    public static function prelinkedUnitsDir(): string
    {
        return \dirname(__DIR__, 2).'/prelinked/helper-runtime/'.self::archKey().'/units';
    }

    /** Per-unit fingerprint: core + the helper source content. */
    public static function unitFingerprint(string $unitSourceAbsPath): string
    {
        $source = @file_get_contents($unitSourceAbsPath);

        return substr(hash('sha256', self::coreFingerprint()."\n".(string) $source), 0, 20);
    }

    /** @return array{fingerprint: string, unit: string, helpers: array<string,string>}|null */
    public static function unitManifest(string $slug, ?string $unitDir = null): ?array
    {
        $path = ($unitDir ?? self::unitDir($slug)).'/manifest.json';
        if (!is_readable($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!\is_array($decoded) || !isset($decoded['fingerprint'], $decoded['helpers']) || !\is_array($decoded['helpers'])) {
            return null;
        }

        return $decoded;
    }

    /** @return array{fingerprint: string, rc: int}|null persisted crash marker */
    public static function unitFailure(string $slug): ?array
    {
        $path = self::unitDir($slug).'/failed.json';
        if (!is_readable($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return \is_array($decoded) && isset($decoded['fingerprint']) ? $decoded : null;
    }

    /**
     * logical(lower) → {symbol, dir} across all FRESH unit manifests.
     * Built lazily once per process; adding a unit invalidates nothing else.
     *
     * The local build cache is scanned first and wins; the committed per-arch
     * prelinked cache (a fresh clone's warm start) fills the gaps. Stale
     * entries in either tier are skipped per unit — a stale committed cache
     * can only make a build slower, never wrong.
     *
     * @return array<string, array{symbol: string, dir: string}>
     */
    private static function helperIndex(): array
    {
        if (null !== self::$helperIndex) {
            return self::$helperIndex;
        }
        $index = [];
        $root = \dirname(__DIR__, 2);
        foreach ([self::unitsDir(), self::prelinkedUnitsDir()] as $unitsRoot) {
            foreach (glob($unitsRoot.'/*/manifest.json') ?: [] as $manifestPath) {
                $unitDir = \dirname($manifestPath);
                $slug = basename($unitDir);
                $manifest = self::unitManifest($slug, $unitDir);
                if (null === $manifest) {
                    continue;
                }
                $sourceAbs = self::resolveUnitSource($root, (string) $manifest['unit']);
                if (null === $sourceAbs || self::unitFingerprint($sourceAbs) !== $manifest['fingerprint']) {
                    continue; // stale — emitter will refresh it
                }
                if (!is_file($unitDir.'/unit.o') || !is_file($unitDir.'/unit.bc')) {
                    continue;
                }
                foreach ($manifest['helpers'] as $logical => $symbol) {
                    if (isset($index[$logical])) {
                        continue; // build cache outranks prelinked
                    }
                    $index[$logical] = ['symbol' => (string) $symbol, 'dir' => $unitDir];
                }
            }
        }

        return self::$helperIndex = $index;
    }

    public static function resolveUnitSource(string $root, string $unitPath): ?string
    {
        if (str_starts_with($unitPath, '/ext/') || str_starts_with($unitPath, '/lib/')) {
            $abs = $root.$unitPath;
        } else {
            $abs = $root.'/lib'.$unitPath;
        }

        return is_file($abs) ? $abs : null;
    }

    /**
     * Bind every cached helper among $logicalNames into $context->functions as
     * an extern declaration with the exact type from the unit's bitcode.
     *
     * @param list<string> $logicalNames
     */
    public static function tryProvide(Context $context, array $logicalNames): bool
    {
        if (!self::enabled()) {
            return false;
        }
        $index = self::helperIndex();
        $lib = $context->llvm->lib;
        $bound = 0;
        foreach ($logicalNames as $logical) {
            $lc = strtolower($logical);
            if (isset($context->functions[$lc]) || !isset($index[$lc])) {
                continue;
            }
            $symbol = $index[$lc]['symbol'];
            $unitDir = $index[$lc]['dir'];

            $existing = $context->module->getNamedFunction($symbol);
            if (null !== $existing) {
                $context->functions[$lc] = $existing;
                self::$usedUnits[$unitDir] = true;
                ++$bound;

                continue;
            }

            $parsed = self::parsedUnit($context, $unitDir);
            if (null === $parsed) {
                continue;
            }
            $source = $parsed->getNamedFunction($symbol);
            if (null === $source) {
                continue;
            }
            $fnType = $lib->LLVMGetElementType($lib->LLVMTypeOf($source->value));
            if (null === $fnType) {
                continue;
            }
            $type = $context->llvm->factory->type($context->context, $fnType);
            $context->functions[$lc] = $context->module->addFunction($symbol, $type);
            self::$usedUnits[$unitDir] = true;
            ++$bound;
        }

        if ($bound > 0 && !self::$loggedHit) {
            $user = getenv('PHP_COMPILER_AOT_USER_SCRIPT');
            if ('1' === $user || 'true' === strtolower((string) $user)) {
                if (\defined('STDERR') && \is_resource(STDERR)) {
                    fwrite(STDERR, sprintf(
                        "phpc build: helper-runtime cache hit (%d helpers, core=%s) (#15889)\n",
                        $bound,
                        self::coreFingerprint()
                    ));
                }
                self::$loggedHit = true;
            }
        }

        return $bound > 0;
    }

    private static function parsedUnit(Context $context, string $unitDir): ?object
    {
        if (isset(self::$parsedUnits[$unitDir])) {
            return self::$parsedUnits[$unitDir];
        }
        $path = $unitDir.'/unit.bc';
        $data = is_file($path) ? (string) file_get_contents($path) : '';
        if ('' === $data) {
            return null;
        }
        // createMemoryBufferWithString instead of ...WithFile: the vendored
        // ...WithFile references an unimported FFI class (latent php-llvm bug).
        $buffer = $context->llvm->createMemoryBufferWithString($data, basename($unitDir).'.bc');

        try {
            // Kept referenced for the process lifetime — declaration types
            // point into the shared LLVMContext.
            return self::$parsedUnits[$unitDir] = $buffer->parseBitcode($context->context);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Linker hook: unit objects whose helpers were bound in this build.
     *
     * @return list<string>
     */
    public static function linkObjects(): array
    {
        if (!self::enabled() || [] === self::$usedUnits) {
            return [];
        }
        $objects = [];
        foreach (array_keys(self::$usedUnits) as $unitDir) {
            $object = $unitDir.'/unit.o';
            if (is_file($object)) {
                $objects[] = $object;
            }
        }

        return $objects;
    }

    public static function markEmitting(): void
    {
        putenv(self::ENV_EMITTING.'=1');
    }
}
