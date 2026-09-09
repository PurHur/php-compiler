<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

use PHPCompiler\Config;

/**
 * Helper-runtime cold-cache warmup for user-script AOT (#15889 / #24302 / #32122).
 *
 * Extracted from {@see HelperRuntimeCache} so warm-for-build / committed-tier
 * presence checks stay a separate TU from the cache hub façade (helper-cache
 * granularity + size-budget ratchet, #36387 / #36403). Callers keep using
 * HelperRuntimeCache::warmForUserAotBuild() thin delegates.
 *
 * php-src analogy: Zend opcache shared-memory / file-cache warm path that
 * skips a full recompile when a committed script cache is already present
 * (Zend/zend_file_cache.c / Zend/zend_accelerator_module.c) — presence of
 * usable compiled units must not force a corpus rebuild.
 */
final class HelperRuntimeWarm
{
    /** Marker for a warmed cache at a given core fingerprint (#15889). */
    private const CORE_MARKER_PREFIX = 'core-';

    private static function coreMarkerPath(): string
    {
        return HelperRuntimeCache::cacheDir().'/'.self::CORE_MARKER_PREFIX.HelperRuntimeCache::coreFingerprint().'.ok';
    }

    /**
     * Best-effort warmup for user-script AOT builds (#15889).
     *
     * When the cache is enabled but cold, run the incremental helper-unit emitter once per core
     * fingerprint. Subsequent builds should be cache hits with no nested helper lowering.
     */
    public static function warmForUserAotBuild(): void
    {
        if (!HelperRuntimeCache::enabled()) {
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
    public static function committedCacheHasUnits(): bool
    {
        $unitsDir = HelperRuntimeCache::prelinkedUnitsDir();
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
}
