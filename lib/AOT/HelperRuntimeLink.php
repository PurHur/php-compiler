<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

/**
 * Helper-runtime link selection + unit.o safety gates (#15889 / #36246 / #36399).
 *
 * Extracted from {@see HelperRuntimeCache} so the cache hub stays under the size-budget
 * ratchet (#36403) and gen-0 / split-TU link hooks stay a separate TU (#36387).
 * Callers keep using HelperRuntimeCache::* thin delegates.
 *
 * php-src analogy: Zend opcache file-cache object selection / shared-memory linking
 * (Zend/zend_file_cache.c + Zend/zend_shared_alloc.c shape) — choose which compiled
 * units participate in the final link without re-lowering.
 */
final class HelperRuntimeLink
{
    /** @var array<string, true> unit dir → merged at link time */
    private static array $usedUnits = [];

    /** Record that a unit's helpers were bound and its unit.o must be linked. */
    public static function markUnitUsed(string $unitDir): void
    {
        if ('' === $unitDir) {
            return;
        }
        self::$usedUnits[$unitDir] = true;
    }

    /**
     * Linker hook: unit objects whose helpers were bound in this build.
     *
     * @return list<string>
     */
    public static function linkObjects(): array
    {
        if (!HelperRuntimeCache::enabled() || [] === self::$usedUnits) {
            return [];
        }
        $objects = [];
        $common = HelperRuntimeCommon::linkObject();
        if (null !== $common) {
            $objects[] = $common;
        }
        // Discovery order follows first-use; sort unit paths so two builds with the same
        // helper set produce identical ld argument lists (#36399 / build-id=sha1).
        $unitObjects = [];
        foreach (array_keys(self::$usedUnits) as $unitDir) {
            $object = $unitDir.'/unit.o';
            if (self::unitObjectIsSafeToLink($unitDir)) {
                $unitObjects[] = $object;
            }
        }
        sort($unitObjects, SORT_STRING);

        return array_merge($objects, $unitObjects);
    }

    /**
     * Basenames of helper units currently selected for link (#36387 object mid-tier).
     *
     * Sorted by slug so mid-tier restore / slugs JSON is byte-stable across runs (#36399).
     *
     * @return list<string>
     */
    public static function usedUnitSlugs(): array
    {
        $slugs = [];
        foreach (array_keys(self::$usedUnits) as $unitDir) {
            $slug = basename((string) $unitDir);
            if ('' !== $slug) {
                $slugs[] = $slug;
            }
        }
        sort($slugs, SORT_STRING);

        return $slugs;
    }

    /**
     * Rebuild {@see $usedUnits} from cached slugs so {@see linkObjects()} works without
     * a fresh lowering pass (#36387 mid-tier `.o` restore).
     *
     * @param list<string> $slugs
     */
    public static function adoptUnitSlugsForLink(array $slugs): void
    {
        self::$usedUnits = [];
        foreach ($slugs as $slug) {
            if (!is_string($slug) || '' === $slug) {
                continue;
            }
            $dir = self::resolveLinkableUnitDir($slug);
            if (null !== $dir) {
                self::$usedUnits[$dir] = true;
            }
        }
    }

    /**
     * Local tier first, then committed prelinked tier (#36387).
     */
    public static function resolveLinkableUnitDir(string $slug): ?string
    {
        foreach ([HelperRuntimeCache::unitDir($slug), HelperRuntimeCache::prelinkedUnitsDir().'/'.$slug] as $dir) {
            if (self::unitObjectIsSafeToLink($dir)) {
                return $dir;
            }
        }

        return null;
    }

    /**
     * A zero-byte unit.o can exist when emit was interrupted; it must not shadow the
     * committed prelinked tier or link as an empty object (undefined helper symbols, #6229).
     */
    public static function unitObjectIsLinkable(string $unitDir): bool
    {
        $object = $unitDir.'/unit.o';

        return is_file($object) && filesize($object) > 0;
    }

    /**
     * Whether a unit.o may participate in an AOT link under HELPER_RUNTIME_O=1.
     *
     * Per-function-section units ({@see \PHPCompiler\JIT\AotGcSections}) require
     * {@see HelperRuntimeCommon} in the link. Without it, `bin/compile.php` (which
     * defaults HELPER_RUNTIME_O=1) produces SIGSEGV on every binary — measured
     * aot-smoke 0/9 exit 139 after an accidental gc_sections prelink (#36246 / #36401).
     * Skip those units so NestedJIT fills the gap until COMMON is opted in.
     */
    public static function unitObjectIsSafeToLink(string $unitDir): bool
    {
        if (!self::unitObjectIsLinkable($unitDir)) {
            return false;
        }
        if (HelperRuntimeCommon::isLinkEnabled()) {
            return true;
        }
        // Fast path: committed corpus without gc_sections → monolithic .text, no readelf.
        $prelinkedRoot = HelperRuntimeCache::prelinkedUnitsDir();
        if (is_string($prelinkedRoot) && '' !== $prelinkedRoot
            && str_starts_with($unitDir, $prelinkedRoot)
            && !self::prelinkedCorpusHasGcSections()) {
            return true;
        }

        return !self::unitObjectHasPerFunctionSections($unitDir.'/unit.o');
    }

    /**
     * Why a built unit.o must not be published into the committed prelinked tree.
     *
     * Mixed gc_sections objects into a monolithic corpus (without COMMON) made
     * HELPER_RUNTIME_O=1 AOT binaries SIGSEGV — aot-smoke 0/9 (#36246 / #36401).
     *
     * @return string|null null when publish is allowed
     */
    public static function refusePrelinkGcMixReason(string $unitObjectPath, bool $migrateToGcSections = false): ?string
    {
        if (!self::unitObjectHasPerFunctionSections($unitObjectPath)) {
            return null;
        }
        if (!HelperRuntimeCommon::commonObjectIsLinkable()) {
            return 'gc_sections unit.o needs linkable common.o before publish';
        }
        if (!self::prelinkedCorpusHasGcSections() && !$migrateToGcSections) {
            return 'gc_sections unit.o into monolithic corpus';
        }

        return null;
    }

    /**
     * True when $objectPath carries AotGcSections per-function ELF sections (.text.<symbol>).
     *
     * Monolithic .text units duplicate runtime symbols that common.o cannot gc (#36246).
     */
    public static function unitObjectHasPerFunctionSections(string $objectPath): bool
    {
        if (!is_file($objectPath) || filesize($objectPath) <= 0) {
            return false;
        }
        $out = [];
        exec(
            'readelf -S '.escapeshellarg($objectPath).' 2>/dev/null | grep -c "\.text\."',
            $out,
            $rc
        );
        if (0 !== $rc || !isset($out[0])) {
            return false;
        }

        return (int) $out[0] > 0;
    }

    /**
     * Committed prelinked corpus was emitted with AotGcSections (per-function .text.* sections).
     *
     * Required before {@see HelperRuntimeCommon} links common.o by default — otherwise
     * -z muldefs keeps duplicate monolithic .text bodies and binaries grow (#36423).
     */
    public static function prelinkedCorpusHasGcSections(): bool
    {
        $manifestPath = \dirname(HelperRuntimeCache::prelinkedUnitsDir()).'/manifest.json';
        if (is_file($manifestPath)) {
            $decoded = json_decode((string) file_get_contents($manifestPath), true);
            if (\is_array($decoded) && !empty($decoded['gc_sections'])) {
                return true;
            }
        }
        $anchor = HelperRuntimeCache::prelinkedUnitsDir().'/'.HelperRuntimeCache::slugFor('/ext/ctype/CtypeJitHelper.php').'/unit.o';

        return self::unitObjectHasPerFunctionSections($anchor);
    }
}
