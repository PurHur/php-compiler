<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\AOT\HelperRuntimeCache;
use PHPCompiler\Config;

/**
 * Cache-entry paths, key material, and freshness for CompileCache (#36387 / #36199).
 *
 * Extracted from {@see CompileCache} so on-disk layout (`module.bc` / `aot.bin` / meta)
 * and compiler fingerprint stay a separate TU while the hub keeps thin public delegates
 * used by bin/compile.php, Runtime, and unit tests.
 *
 * No new C ABI. php-src analogy: Zend opcache script key + freshness against a shared
 * accelerator hash (Zend/zend_accelerator_hash.c / Zend/zend_file_cache.c) — here the
 * key folds source bytes + {@see fingerprint()} so a compiler/layout change invalidates
 * prior entries without restamping.
 */
final class CompileCacheKeyLayout
{
    public const META_VERSION = 1;

    public static function cacheRoot(): string
    {
        $override = Config::getenv('PHP_COMPILER_CACHE_DIR');
        if (is_string($override) && '' !== $override) {
            return rtrim($override, '/');
        }

        return dirname(__DIR__, 2).'/.php-compiler-cache';
    }

    public static function computeKey(string $sourcePath, string $sourceCode): string
    {
        $resolved = realpath($sourcePath);
        $pathPart = false !== $resolved ? $resolved : $sourcePath;
        $mtime = is_file($pathPart) ? (string) filemtime($pathPart) : '0';

        return hash('sha256', implode("\0", [
            $pathPart,
            $mtime,
            strlen($sourceCode),
            hash('sha256', $sourceCode),
            self::fingerprint(),
        ]));
    }

    public static function entryDir(string $key): string
    {
        return self::cacheRoot().'/'.$key;
    }

    public static function bitcodePath(string $key): string
    {
        return self::entryDir($key).'/module.bc';
    }

    /**
     * AOT freshness marker when full-module bitcode cannot round-trip (#36387).
     */
    public static function stampPath(string $key): string
    {
        return self::entryDir($key).'/fresh.stamp';
    }

    /**
     * Linked AOT executable bytes for an unchanged-source rebuild (#36387 / #36199).
     *
     * Bitcode restore still re-runs loadJitContext + object emit + link (~5 s for hello).
     * Caching the final binary lets warm `phpc build` skip that path entirely.
     */
    public static function artifactPath(string $key): string
    {
        return self::entryDir($key).'/aot.bin';
    }

    /**
     * Emitted user-script object for mid-tier restore (#36387 / #36199).
     *
     * When `aot.bin` is missing but this `.o` is fresh, {@see CompileCacheArtifactPersist::tryRestoreObjectAndLink()}
     * skips LLVM Context / emitToFile and only re-runs the system link with the recorded
     * helper-runtime unit slugs.
     */
    public static function objectPath(string $key): string
    {
        return self::entryDir($key).'/aot.o';
    }

    /** Sidecar listing helper-runtime unit slugs needed to link {@see objectPath()}. */
    public static function linkManifestPath(string $key): string
    {
        return self::entryDir($key).'/link.json';
    }

    public static function metaPath(string $key): string
    {
        return self::entryDir($key).'/meta.json';
    }

    /**
     * @return array{version: int, fingerprint: string, exports: list<array{llvm: string, signature: string, scoped: string}>}|null
     */
    public static function readMeta(string $key): ?array
    {
        $path = self::metaPath($key);
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if (false === $raw) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        if ((int) ($decoded['version'] ?? 0) !== self::META_VERSION) {
            return null;
        }
        if (($decoded['fingerprint'] ?? '') !== self::fingerprint()) {
            return null;
        }
        if (!isset($decoded['exports']) || !is_array($decoded['exports'])) {
            return null;
        }

        return $decoded;
    }

    public static function isFresh(string $key, string $sourcePath, string $sourceCode): bool
    {
        if (!CompileCache::isEnabled()) {
            return false;
        }
        if (self::computeKey($sourcePath, $sourceCode) !== $key) {
            return false;
        }
        if (null === self::readMeta($key)) {
            return false;
        }

        // JIT: module.bc. AOT: fresh.stamp and/or module.bc (void*→i8* makes bitcode legal).
        // Artifact / object alone also count so mid-tier restore stays valid (#36387).
        return self::hasDurableMarker($key);
    }

    /** True when the cache entry has a durable on-disk marker for this key. */
    public static function hasDurableMarker(string $key): bool
    {
        if (is_file(self::stampPath($key))) {
            return true;
        }
        if (is_file(self::bitcodePath($key))) {
            return true;
        }
        if (is_file(self::artifactPath($key)) && filesize(self::artifactPath($key)) > 0) {
            return true;
        }

        return is_file(self::objectPath($key)) && filesize(self::objectPath($key)) > 0;
    }

    /**
     * Compiler fingerprint for project-index / meta durability (#36387).
     */
    public static function compilerFingerprint(): string
    {
        return self::fingerprint();
    }

    public static function fingerprint(): string
    {
        static $cached = null;
        if (null !== $cached) {
            return $cached;
        }

        $parts = [];
        $lock = dirname(__DIR__, 2).'/composer.lock';
        if (is_file($lock)) {
            $parts[] = hash_file('sha256', $lock) ?: '';
        }
        $parts[] = HelperRuntimeCache::llvmIdentityToken();
        $parts[] = hash_file('sha256', __DIR__.'/../JIT/Context.php') ?: '';
        $parts[] = hash_file('sha256', __DIR__.'/../JIT.php') ?: '';
        // Hashtable string-key DJB index / unset (#36191 / #36732) — artifact restore
        // must not keep pre-fix binaries when only Type/HashTable.php changed.
        $parts[] = hash_file('sha256', __DIR__.'/Builtin/Type/HashTable.php') ?: '';
        $parts[] = hash_file('sha256', __DIR__.'/Builtin/AttributeRegistryLowering.php') ?: '';
        $parts[] = hash_file('sha256', __DIR__.'/../Runtime.php') ?: '';
        $parts[] = LazyBuiltins::fingerprintSegment();
        $parts[] = HelperRuntimeCache::coreFingerprint();
        $parts[] = HelperRuntimeCache::cacheKeySegment();
        foreach (['PHP_COMPILER_AOT_USER_SCRIPT', 'PHP_COMPILER_HELPER_RUNTIME_O'] as $envKey) {
            $flag = getenv($envKey);
            $parts[] = $envKey.'='.(false === $flag ? '' : $flag);
        }

        $cached = hash('sha256', implode("\0", $parts));

        return $cached;
    }
}
