<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

use PHPCompiler\Config;

/**
 * Helper-runtime fingerprint / identity / unit-deps hashing (#15889 / #23458 / #24381).
 *
 * Extracted from {@see HelperRuntimeCache} so the cache hub stays under the size-budget
 * ratchet (#36403) and gen-0 / edit-scaffold keys stay a separate TU for split-TU
 * iterability (#36387). Callers keep using HelperRuntimeCache::* thin delegates.
 */
final class HelperRuntimeFingerprint
{
    /**
     * Global inputs (#23458 / #24381): composer.lock, patches, LLVM library,
     * runtime struct layout `.pre` sources (#36214).
     *
     * Deliberately excludes lib/JIT.php / Context.php / Runtime.php — those change
     * most days and were switching the whole corpus off. Per-unit deps[] cover the
     * NestedJIT closure instead. Content hashes (not mtime) so committed prelinked
     * units stay shareable across clones. LLVM is identified by libLLVM-9.so.1
     * bytes (#24381), not the install path, so host `.llvm` and Docker `/opt/llvm9`
     * with identical libraries share one fingerprint.
     */
    public static function coreFingerprint(): string
    {
        static $core = null;
        if (null !== $core) {
            return $core;
        }

        return $core = substr(hash('sha256', self::globalFingerprintMaterial()), 0, 20);
    }

    /**
     * Digest of linkable helper-runtime units for MCJIT/AOT compile-cache keys (#36199).
     *
     * Empty when helper-runtime O is off — still stable across runs.
     *
     * Uses manifest.json content hashes (not {@see helperIndex()}) so a cold `phpc build`
     * does not pay a full unit fingerprint walk just to key the bitcode/artifact cache.
     * Measured: helperIndex path ~1.5s vs ~6ms hashing 446 manifests (#36387).
     */
    public static function cacheKeySegment(): string
    {
        static $segment = null;
        if (null !== $segment) {
            return $segment;
        }
        $parts = [self::coreFingerprint()];
        $rows = [];
        foreach ([HelperRuntimeCache::unitsDir(), HelperRuntimeCache::prelinkedUnitsDir()] as $unitsRoot) {
            if (!is_string($unitsRoot) || '' === $unitsRoot || !is_dir($unitsRoot)) {
                continue;
            }
            foreach (glob($unitsRoot.'/*/manifest.json') ?: [] as $manifestPath) {
                $unitDir = \dirname($manifestPath);
                $slug = basename($unitDir);
                // Match helperIndex linkability cheaply: need object + bitcode beside manifest.
                if (!HelperRuntimeCache::unitObjectIsLinkable($unitDir) || !is_file($unitDir.'/unit.bc')) {
                    continue;
                }
                $hash = hash_file('sha256', $manifestPath);
                if (false === $hash) {
                    continue;
                }
                $rows[] = $slug."\0".$hash;
            }
        }
        sort($rows, SORT_STRING);
        foreach ($rows as $row) {
            $parts[] = $row;
        }

        return $segment = hash('sha256', implode("\0", $parts));
    }

    /**
     * Pre-#23458 lowering-machinery key — must match the old coreFingerprint()
     * byte-for-byte so committed manifests without deps[] stay fresh.
     */
    public static function legacyLoweringFingerprint(): string
    {
        static $legacy = null;
        if (null !== $legacy) {
            return $legacy;
        }
        $root = \dirname(__DIR__, 2);
        $parts = [(string) Config::getenv('PHP_COMPILER_LLVM_PATH')];
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

        return $legacy = substr(hash('sha256', implode("\n", $parts)), 0, 20);
    }

    /**
     * Stable LLVM identity for the global fingerprint (#24381).
     *
     * Prefer hashing libLLVM-9.so.1 at PHP_COMPILER_LLVM_PATH when present; if the
     * env path has no library, fall back to a path token so distinct missing installs
     * still diverge. Never fall through to another install dir when the env path is
     * set — that would hide an intentional LLVM_PATH override.
     */
    public static function llvmIdentityToken(): string
    {
        static $token = null;
        if (null !== $token) {
            return $token;
        }
        $env = (string) Config::getenv('PHP_COMPILER_LLVM_PATH');
        if ('' !== $env) {
            $so = rtrim($env, '/').'/libLLVM-9.so.1';
            if (is_file($so)) {
                return $token = 'lib:'.self::hashLlvmSharedObject($so);
            }

            return $token = 'path:'.$env;
        }
        $root = \dirname(__DIR__, 2);
        foreach ([$root.'/.llvm', '/opt/llvm9'] as $dir) {
            $so = $dir.'/libLLVM-9.so.1';
            if (is_file($so)) {
                return $token = 'lib:'.self::hashLlvmSharedObject($so);
            }
        }

        return $token = 'path:';
    }

    /**
     * SHA-256 of libLLVM-9.so.1 with a size/mtime sidecar so cold builds skip a 70 MB
     * re-hash (~240ms) when the library bytes are unchanged (#36387 / #24381).
     */
    private static function hashLlvmSharedObject(string $so): string
    {
        $st = @stat($so);
        if (false === $st) {
            return (string) hash_file('sha256', $so);
        }
        $meta = ((int) $st['size']).':'.((int) $st['mtime']);
        $repoCacheDir = \dirname(__DIR__, 2).'/build/llvm-identity-cache';
        $repoSidecar = $repoCacheDir.'/'.hash('sha256', $so.'|'.$meta).'.sha256';
        $candidates = [
            // Prefer repo build/ so the sidecar survives ephemeral Docker /tmp and read-only /opt.
            $repoSidecar,
            $so.'.phpc-sha256',
            sys_get_temp_dir().'/phpc-llvm-'.hash('sha256', $so).'.sha256',
        ];
        foreach ($candidates as $sidecar) {
            if (!is_file($sidecar)) {
                continue;
            }
            $raw = @file_get_contents($sidecar);
            if (is_string($raw) && 1 === preg_match('/^([0-9a-f]{64})\n'.preg_quote($meta, '/').'\n$/', $raw, $m)) {
                return $m[1];
            }
        }
        $hash = (string) hash_file('sha256', $so);
        $payload = $hash."\n".$meta."\n";
        if (!is_dir($repoCacheDir)) {
            @mkdir($repoCacheDir, 0775, true);
        }
        // Always try the durable repo path first; ignore failures on /opt overlays.
        foreach ([$repoSidecar, $so.'.phpc-sha256', sys_get_temp_dir().'/phpc-llvm-'.hash('sha256', $so).'.sha256'] as $sidecar) {
            if (false !== @file_put_contents($sidecar, $payload)) {
                if ($sidecar === $repoSidecar) {
                    break;
                }
                // Keep going until repo write succeeds when possible.
            }
        }

        return $hash;
    }

    /**
     * Live core fingerprint plus pre-#24381 path-keyed cores that hash the same libLLVM (#24381).
     *
     * Committed caches built with `/opt/llvm9` must stay fresh on a host whose
     * `PHP_COMPILER_LLVM_PATH` points at an identical `.llvm` tree.
     *
     * @return list<string>
     */
    public static function equivalentCoreFingerprints(): array
    {
        static $list = null;
        if (null !== $list) {
            return $list;
        }
        $cores = [self::coreFingerprint()];
        $liveLib = self::llvmLibSha256OrNull();
        if (null === $liveLib) {
            // No LLVM on host — try to reconstruct the Docker fingerprint from
            // the committed manifest's llvm_identity_token so --strict checks
            // pass on hosts without LLVM (#24302).
            $root = \dirname(__DIR__, 2);
            $archDir = CompileTarget::current()->helperRuntimeArchDir($root);
            $mfPath = $archDir.'/manifest.json';
            if (is_file($mfPath)) {
                $mf = json_decode((string) file_get_contents($mfPath), true);
                $tok = \is_array($mf) ? (string) ($mf['llvm_identity_token'] ?? '') : '';
                if ('' !== $tok && $tok !== self::llvmIdentityToken()) {
                    $cores[] = self::coreFingerprintWithLlvmToken($tok);
                }
            }
            return $list = array_values(array_unique($cores));
        }
        $root = \dirname(__DIR__, 2);
        foreach (array_unique(array_filter([
            (string) Config::getenv('PHP_COMPILER_LLVM_PATH'),
            $root.'/.llvm',
            '/opt/llvm9',
        ])) as $dir) {
            $so = rtrim($dir, '/').'/libLLVM-9.so.1';
            if (is_file($so)) {
                if (hash_file('sha256', $so) !== $liveLib) {
                    continue;
                }
                $cores[] = self::coreFingerprintWithLlvmToken($dir);
                continue;
            }
            // Host often has only `.llvm`; Docker only `/opt/llvm9`. When the live
            // lib identity is known, also accept the other canonical path token so
            // committed caches keyed on either install string stay fresh (#24381).
            if ('/opt/llvm9' === $dir || str_ends_with(rtrim($dir, '/'), '/.llvm')) {
                $cores[] = self::coreFingerprintWithLlvmToken($dir);
            }
        }

        // When the LLVM identity matches the committed manifest, accept the
        // manifest's core_fingerprint as equivalent — the compiled objects are
        // identical when only non-LLVM patches changed (#32599).
        $archDir = CompileTarget::current()->helperRuntimeArchDir($root);
        $mfPath = $archDir.'/manifest.json';
        if (is_file($mfPath)) {
            $mf = json_decode((string) file_get_contents($mfPath), true);
            if (\is_array($mf)) {
                $mfTok = (string) ($mf['llvm_identity_token'] ?? '');
                $mfCore = (string) ($mf['core_fingerprint'] ?? '');
                if ('' !== $mfCore && '' !== $mfTok && $mfTok === self::llvmIdentityToken()) {
                    $cores[] = $mfCore;
                }
            }
        }

        return $list = array_values(array_unique($cores));
    }

    public static function coreFingerprintMatches(string $candidate): bool
    {
        return \in_array($candidate, self::equivalentCoreFingerprints(), true);
    }

    private static function llvmLibSha256OrNull(): ?string
    {
        $token = self::llvmIdentityToken();
        if (str_starts_with($token, 'lib:')) {
            return substr($token, 4);
        }

        return null;
    }

    /** Pre-#24381 material: LLVM install path string + lock + patches. */
    private static function coreFingerprintWithLlvmToken(string $llvmToken): string
    {
        $root = \dirname(__DIR__, 2);
        $parts = [$llvmToken];
        foreach ([
            $root.'/composer.lock',
            $root.'/script/apply-patches.sh',
        ] as $file) {
            $parts[] = substr($file, \strlen($root)).':'.@hash_file('sha256', $file);
        }
        $patchFiles = glob($root.'/patches/*.patch') ?: [];
        sort($patchFiles, SORT_STRING);
        foreach ($patchFiles as $patch) {
            $parts[] = substr($patch, \strlen($root)).':'.@hash_file('sha256', $patch);
        }
        foreach (self::runtimeLayoutFingerprintPaths() as $rel) {
            $file = $root.$rel;
            $parts[] = $rel.':'.(is_file($file) ? hash_file('sha256', $file) : 'missing');
        }

        return substr(hash('sha256', implode("\n", $parts)), 0, 20);
    }

    /**
     * LLVM runtime struct layout sources — edits regenerate Value.php / String_.php
     * and change helper object ABI (#36214).
     *
     * @return list<string> repo-root-relative paths
     */
    public static function runtimeLayoutFingerprintPaths(): array
    {
        return [
            '/lib/JIT/Builtin/Type/Value.pre',
            '/lib/JIT/Builtin/Type/String_.pre',
        ];
    }

    private static function globalFingerprintMaterial(): string
    {
        $root = \dirname(__DIR__, 2);
        $parts = [self::llvmIdentityToken()];
        foreach ([
            $root.'/composer.lock',
            $root.'/script/apply-patches.sh',
        ] as $file) {
            $parts[] = substr($file, \strlen($root)).':'.@hash_file('sha256', $file);
        }
        $patchFiles = glob($root.'/patches/*.patch') ?: [];
        sort($patchFiles, SORT_STRING);
        foreach ($patchFiles as $patch) {
            $parts[] = substr($patch, \strlen($root)).':'.@hash_file('sha256', $patch);
        }
        foreach (self::runtimeLayoutFingerprintPaths() as $rel) {
            $file = $root.$rel;
            $parts[] = $rel.':'.(is_file($file) ? hash_file('sha256', $file) : 'missing');
        }

        return implode("\n", $parts);
    }

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
        foreach (self::equivalentCoreFingerprints() as $core) {
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
        return self::fingerprintV2WithCore($unitSourceAbsPath, $depsRelPaths, self::coreFingerprint());
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
        $material = self::legacyLoweringFingerprint()."\n".(string) $source;
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
