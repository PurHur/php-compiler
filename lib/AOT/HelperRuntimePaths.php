<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

/**
 * Helper-runtime path / env / arch directory resolution (#15889 / #36391).
 *
 * Extracted from {@see HelperRuntimeCache} so cache enablement, local vs
 * committed unit directories, and slug/arch keys stay a separate TU from the
 * cache hub façade (helper-cache granularity + size-budget ratchet,
 * #36387 / #36403). Callers keep using HelperRuntimeCache::* thin delegates.
 *
 * php-src analogy: Zend opcache file-cache / shared-memory path resolution
 * (Zend/zend_file_cache.c / Zend/zend_shared_alloc.c) — locate the compiled
 * unit store for the active architecture without re-lowering.
 */
final class HelperRuntimePaths
{
    private const ENV_FLAG = 'PHP_COMPILER_HELPER_RUNTIME_O';

    private const ENV_DIR = 'PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR';

    /** Guard so the emitter itself never consumes the cache. */
    private const ENV_EMITTING = 'PHP_COMPILER_HELPER_RUNTIME_EMITTING';

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

    public static function markEmitting(): void
    {
        putenv(self::ENV_EMITTING.'=1');
    }
}
