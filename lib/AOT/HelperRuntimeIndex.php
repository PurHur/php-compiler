<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

/**
 * Helper-runtime unit index / manifest scan (#15889 / #23458).
 *
 * Extracted from {@see HelperRuntimeCache} so fresh-unit discovery stays a
 * separate TU from warm-up / path / fingerprint / link / bind façades
 * (helper-cache granularity + size-budget ratchet, #36387 / #36403).
 * Callers keep using HelperRuntimeCache::* thin delegates.
 *
 * php-src analogy: Zend opcache shared-memory script table lookup
 * (Zend/zend_shared_alloc.c / Zend/zend_file_cache.c) — resolve which compiled
 * units are fresh enough to bind without re-lowering.
 */
final class HelperRuntimeIndex
{
    /** @var array<string, array{symbol: string, dir: string}>|null logical(lower) → binding */
    private static ?array $helperIndex = null;

    /** Drop the process-local index so the next {@see helperIndex()} rescan. */
    public static function invalidate(): void
    {
        self::$helperIndex = null;
    }

    /** @return array{fingerprint: string, unit: string, helpers: array<string,string>}|null */
    public static function unitManifest(string $slug, ?string $unitDir = null): ?array
    {
        $path = ($unitDir ?? HelperRuntimeCache::unitDir($slug)).'/manifest.json';
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
        $path = HelperRuntimeCache::unitDir($slug).'/failed.json';
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
    public static function helperIndex(): array
    {
        if (null !== self::$helperIndex) {
            return self::$helperIndex;
        }
        $index = [];
        $root = \dirname(__DIR__, 2);
        foreach ([HelperRuntimeCache::unitsDir(), HelperRuntimeCache::prelinkedUnitsDir()] as $unitsRoot) {
            foreach (glob($unitsRoot.'/*/manifest.json') ?: [] as $manifestPath) {
                $unitDir = \dirname($manifestPath);
                $slug = basename($unitDir);
                $manifest = self::unitManifest($slug, $unitDir);
                if (null === $manifest) {
                    continue;
                }
                $sourceAbs = self::resolveUnitSource($root, (string) $manifest['unit']);
                if (null === $sourceAbs || !HelperRuntimeCache::manifestFingerprintMatches($manifest, $sourceAbs)) {
                    continue; // stale — emitter will refresh it
                }
                if (!HelperRuntimeCache::unitObjectIsSafeToLink($unitDir) || !is_file($unitDir.'/unit.bc')) {
                    continue;
                }
                if (!isset($manifest['init_symbol']) || '' === (string) $manifest['init_symbol']) {
                    continue; // pre-init-era unit: its module state never runs — unusable (#16075 step 4)
                }
                if (isset($manifest['runtime_safe']) && false === $manifest['runtime_safe']) {
                    continue; // known cross-module ABI hazard (baked class ids) — see emitter blocklist
                }
                foreach ($manifest['helpers'] as $logical => $symbol) {
                    if (isset($index[$logical])) {
                        continue; // build cache outranks prelinked
                    }
                    $index[$logical] = [
                        'symbol' => (string) $symbol,
                        'dir' => $unitDir,
                        'init' => (string) $manifest['init_symbol'],
                        'shutdown' => isset($manifest['shutdown_symbol']) ? (string) $manifest['shutdown_symbol'] : null,
                        'init_via_global_ctor' => !empty($manifest['init_via_global_ctor']),
                    ];
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
}
