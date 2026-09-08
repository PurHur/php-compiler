<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Config;

/**
 * Thin public KeyLayout / enable-gate delegates for AOT CompileCache (#36387).
 *
 * Extracted from {@see CompileCache} so {@see isEnabled()} and the path / key /
 * freshness wrappers that forward to {@see CompileCacheKeyLayout} stay a separate
 * TU (split-TU / size-budget ratchet) while the hub keeps ArtifactPersist /
 * SemanticHash / ProjectIndex delegates and trait composition.
 *
 * Move-only — no new C ABI. php-src analogy: Zend opcache module enable + script
 * key / freshness helpers surface (Zend/zend_accelerator_module.c /
 * Zend/zend_accelerator_hash.c / Zend/zend_file_cache.c) before the file-cache
 * restore path runs.
 */
trait CompileCacheKeyLayoutFacade
{
    public static function isEnabled(): bool
    {
        $flag = Config::getenv('PHP_COMPILER_CACHE');
        if (false !== $flag && ('0' === $flag || 'false' === strtolower($flag))) {
            return false;
        }
        if (Config::getenv('PHP_COMPILER_SELFHOST_AOT') === '1') {
            return false;
        }
        if (EmitTuMode::isMinimalRuntime()) {
            return false;
        }

        return true;
    }

    /**
     * Compiler fingerprint for project-index / meta durability (#36387).
     *
     * @see CompileCacheKeyLayout::compilerFingerprint()
     */
    public static function compilerFingerprint(): string
    {
        return CompileCacheKeyLayout::compilerFingerprint();
    }

    /** @see CompileCacheKeyLayout::cacheRoot() */
    public static function cacheRoot(): string
    {
        return CompileCacheKeyLayout::cacheRoot();
    }

    /** @see CompileCacheKeyLayout::computeKey() */
    public static function computeKey(string $sourcePath, string $sourceCode): string
    {
        return CompileCacheKeyLayout::computeKey($sourcePath, $sourceCode);
    }

    /** @see CompileCacheKeyLayout::entryDir() */
    public static function entryDir(string $key): string
    {
        return CompileCacheKeyLayout::entryDir($key);
    }

    /** @see CompileCacheKeyLayout::bitcodePath() */
    public static function bitcodePath(string $key): string
    {
        return CompileCacheKeyLayout::bitcodePath($key);
    }

    /** @see CompileCacheKeyLayout::stampPath() */
    public static function stampPath(string $key): string
    {
        return CompileCacheKeyLayout::stampPath($key);
    }

    /** @see CompileCacheKeyLayout::artifactPath() */
    public static function artifactPath(string $key): string
    {
        return CompileCacheKeyLayout::artifactPath($key);
    }

    /** @see CompileCacheKeyLayout::objectPath() */
    public static function objectPath(string $key): string
    {
        return CompileCacheKeyLayout::objectPath($key);
    }

    /** @see CompileCacheKeyLayout::linkManifestPath() */
    public static function linkManifestPath(string $key): string
    {
        return CompileCacheKeyLayout::linkManifestPath($key);
    }

    /** @see CompileCacheKeyLayout::metaPath() */
    public static function metaPath(string $key): string
    {
        return CompileCacheKeyLayout::metaPath($key);
    }

    /**
     * @return array{version: int, fingerprint: string, exports: list<array{llvm: string, signature: string, scoped: string}>}|null
     *
     * @see CompileCacheKeyLayout::readMeta()
     */
    public static function readMeta(string $key): ?array
    {
        return CompileCacheKeyLayout::readMeta($key);
    }

    /** @see CompileCacheKeyLayout::isFresh() */
    public static function isFresh(string $key, string $sourcePath, string $sourceCode): bool
    {
        return CompileCacheKeyLayout::isFresh($key, $sourcePath, $sourceCode);
    }

    /** @see CompileCacheKeyLayout::hasDurableMarker() */
    public static function hasDurableMarker(string $key): bool
    {
        return CompileCacheKeyLayout::hasDurableMarker($key);
    }
}
