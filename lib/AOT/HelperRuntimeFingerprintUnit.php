<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

/**
 * Per-unit helper-runtime fingerprint / deps / manifest v2 hashing (#15889 / #23458 / #24381).
 *
 * Extracted from {@see HelperRuntimeFingerprint} so core/LLVM identity stays a separate TU
 * from unitFingerprint / NestedJIT deps / migrateManifestToV2 (helper-cache granularity +
 * size-budget ratchet, #36387 / #36403). Callers keep using HelperRuntimeFingerprint::*
 * (and HelperRuntimeCache::*) thin delegates.
 *
 * php-src analogy: Zend opcache script keying separates shared accelerator identity
 * (Zend/zend_accelerator_hash.c / zend_accel_hash) from per-script file+deps digests
 * (Zend/zend_file_cache.c) — global cache identity must not collapse into unit hashing.
 */
final class HelperRuntimeFingerprintUnit
{
    /**
     * Repo-root relative path (/lib/… or /ext/…) for an absolute file, or null.
     */
    public static function repoRelPath(string $absPath): ?string
    {
        $root = \dirname(__DIR__, 2);
        $real = realpath($absPath) ?: $absPath;
        $real = str_replace('\\', '/', $real);
        $rootNorm = str_replace('\\', '/', $root);
        if (!str_starts_with($real, $rootNorm.'/')) {
            return null;
        }
        $rel = substr($real, \strlen($rootNorm));
        if (str_starts_with($rel, '/lib/') || str_starts_with($rel, '/ext/')) {
            return $rel;
        }

        return null;
    }

    /**
     * Build the NestedJIT dependency list recorded at emit time (#23458).
     *
     * @param list<string> $compiledAbsPaths from Context::listJitCompiledIncludePaths()
     *
     * @return list<string> sorted unique repo-relative paths
     */
    public static function dependencyRelPathsForEmit(string $unitSourceAbsPath, array $compiledAbsPaths): array
    {
        $rels = [];
        $unitRel = self::repoRelPath($unitSourceAbsPath);
        if (null !== $unitRel) {
            $rels[$unitRel] = true;
        }
        foreach ($compiledAbsPaths as $abs) {
            $rel = self::repoRelPath((string) $abs);
            if (null !== $rel) {
                $rels[$rel] = true;
            }
        }
        foreach (self::unitExtraDependencyRelPaths($unitSourceAbsPath) as $rel) {
            $rels[$rel] = true;
        }
        // One-level same-directory class refs from the unit + NestedJIT'd files only
        // (do not recurse through VmString → half of ext/standard).
        $seed = array_keys($rels);
        foreach ($seed as $rel) {
            foreach (self::sameDirClassReferenceRelPaths($rel) as $ref) {
                $rels[$ref] = true;
            }
        }
        $keys = array_keys($rels);
        sort($keys, SORT_STRING);

        return $keys;
    }

    /**
     * @return list<string> /lib|ext/.../Foo.php paths referenced as Foo:: in $rel
     */
    private static function sameDirClassReferenceRelPaths(string $rel): array
    {
        $root = \dirname(__DIR__, 2);
        $abs = $root.$rel;
        if (!is_file($abs)) {
            return [];
        }
        $code = (string) @file_get_contents($abs);
        if ('' === $code || !preg_match_all('/\b([A-Z][A-Za-z0-9_]*)::/', $code, $m)) {
            return [];
        }
        $dir = str_replace('\\', '/', \dirname($rel));
        $out = [];
        foreach (array_unique($m[1]) as $class) {
            $candidate = $dir.'/'.$class.'.php';
            if (is_file($root.$candidate)) {
                $out[] = $candidate;
            }
        }

        return $out;
    }

    /**
     * @param list<string>|null $depsRelPaths repo-relative paths; null = v2 with unit-only + extras
     */
    public static function unitFingerprint(string $unitSourceAbsPath, ?array $depsRelPaths = null): string
    {
        if (null === $depsRelPaths) {
            $depsRelPaths = self::dependencyRelPathsForEmit($unitSourceAbsPath, []);
        }

        return self::fingerprintV2($unitSourceAbsPath, $depsRelPaths);
    }

    /**
     * Fingerprint expected for an on-disk manifest (v2 deps[] or legacy v1).
     *
     * @param array{fingerprint?: string, unit?: string, deps?: list<string>|mixed} $manifest
     */
    public static function expectedFingerprintForManifest(array $manifest, string $unitSourceAbsPath): string
    {
        if (isset($manifest['deps']) && \is_array($manifest['deps'])) {
            $deps = [];
            foreach ($manifest['deps'] as $dep) {
                if (\is_string($dep) && '' !== $dep) {
                    $deps[] = $dep;
                }
            }

            return self::fingerprintV2($unitSourceAbsPath, $deps);
        }

        return self::fingerprintV1Legacy($unitSourceAbsPath);
    }

    public static function manifestFingerprintMatches(array $manifest, string $unitSourceAbsPath): bool
    {
        if (!isset($manifest['fingerprint'])) {
            return false;
        }
        $stored = (string) $manifest['fingerprint'];
        if ($stored === self::expectedFingerprintForManifest($manifest, $unitSourceAbsPath)) {
            return true;
        }
        // #24381: unit fps embed coreFingerprint; accept path-keyed cores that
        // identify the same libLLVM-9.so.1 bytes as the live install.
        if (!isset($manifest['deps']) || !\is_array($manifest['deps'])) {
            return false;
        }
        $deps = [];
        foreach ($manifest['deps'] as $dep) {
            if (\is_string($dep) && '' !== $dep) {
                $deps[] = $dep;
            }
        }
        foreach (HelperRuntimeFingerprint::equivalentCoreFingerprints() as $core) {
            if ($stored === self::fingerprintV2WithCore($unitSourceAbsPath, $deps, $core)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rewrite a legacy (no deps[]) manifest to v2 using static NestedJIT-ish deps (#23458).
     * Keeps unit.o / unit.bc; only updates fingerprint + deps. Returns null when not legacy-fresh.
     *
     * @param array<string, mixed> $manifest
     *
     * @return array<string, mixed>|null
     */
    public static function migrateManifestToV2(array $manifest, string $unitSourceAbsPath): ?array
    {
        $isV2 = isset($manifest['deps']) && \is_array($manifest['deps']);
        if (!$isV2 && !self::manifestFingerprintMatches($manifest, $unitSourceAbsPath)) {
            return null; // stale legacy — needs full re-emit
        }
        // Always recompute static deps (one-level) so migrate can shrink a prior over-expansion.
        $deps = self::dependencyRelPathsForEmit($unitSourceAbsPath, []);
        $manifest['deps'] = $deps;
        $manifest['fingerprint'] = self::fingerprintV2($unitSourceAbsPath, $deps);
        $manifest['fingerprint_version'] = 2;

        return $manifest;
    }

    /**
     * @param list<string> $depsRelPaths
     */
    public static function fingerprintV2(string $unitSourceAbsPath, array $depsRelPaths): string
    {
        return self::fingerprintV2WithCore($unitSourceAbsPath, $depsRelPaths, HelperRuntimeFingerprint::coreFingerprint());
    }

    /**
     * @param list<string> $depsRelPaths
     */
    public static function fingerprintV2WithCore(string $unitSourceAbsPath, array $depsRelPaths, string $core): string
    {
        $root = \dirname(__DIR__, 2);
        $source = @file_get_contents($unitSourceAbsPath);
        $parts = [
            $core,
            'v2',
            (string) $source,
        ];
        $deps = array_values(array_unique(array_filter($depsRelPaths, static fn ($d) => \is_string($d) && '' !== $d)));
        sort($deps, SORT_STRING);
        foreach ($deps as $rel) {
            $parts[] = $rel.':'.@hash_file('sha256', $root.$rel);
        }

        return substr(hash('sha256', implode("\n", $parts)), 0, 20);
    }

    private static function fingerprintV1Legacy(string $unitSourceAbsPath): string
    {
        $source = @file_get_contents($unitSourceAbsPath);
        $material = HelperRuntimeFingerprint::legacyLoweringFingerprint()."\n".(string) $source;
        $extra = self::unitDependencyFingerprintMaterial($unitSourceAbsPath);
        if ('' !== $extra) {
            $material .= "\n".$extra;
        }

        return substr(hash('sha256', $material), 0, 20);
    }

    /**
     * Nested helper units embed ext/dom semantics pulled in at emit time; hash SSOT
     * alongside the helper stub so VmDom edits invalidate stale units (#17954).
     *
     * Float math *JitHelper units NestedJIT through {@see \PHPCompiler\ext\standard\JitFdiv}
     * boxed-double lowering — hash it so JitFdiv edits invalidate those units (#20651).
     *
     * @return list<string>
     */
    private static function unitExtraDependencyRelPaths(string $unitSourceAbsPath): array
    {
        $parts = [];
        $root = \dirname(__DIR__, 2);
        if (str_starts_with($unitSourceAbsPath, $root.'/ext/dom/')) {
            foreach ([
                '/ext/dom/VmDom.php',
                '/ext/dom/VmDomJitFrame.php',
                '/ext/dom/DomRegistry.php',
            ] as $rel) {
                $parts[] = $rel;
            }
        }
        $base = \basename($unitSourceAbsPath);
        if (1 === preg_match(
            '/^(Fpow|Nextafter|Sqrt|Hypot|Log|Log10|Log1p|Sin|Cos|Tan|Asin|Acos|Atan|Atan2|Sinh|Cosh|Tanh|Exp|Expm1|Floor|Ceil|Round|Fmod|Fdiv)JitHelper\.php$/',
            $base
        )) {
            $parts[] = '/ext/standard/JitFdiv.php';
        }

        return $parts;
    }

    /**
     * Legacy v1 extra material (content hashes) — kept for fingerprintV1Legacy.
     */
    private static function unitDependencyFingerprintMaterial(string $unitSourceAbsPath): string
    {
        $root = \dirname(__DIR__, 2);
        $parts = [];
        foreach (self::unitExtraDependencyRelPaths($unitSourceAbsPath) as $rel) {
            $parts[] = $rel.':'.@hash_file('sha256', $root.$rel);
        }

        return implode("\n", $parts);
    }
}
