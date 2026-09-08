<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Artifact / object mid-tier warm restore facade for AOT CompileCache (#36387).
 *
 * Thin public delegates onto {@see CompileCacheArtifactPersist} so the hub keeps
 * ratcheting under the size-budget split-TU program. Distinct from KeyLayout path
 * helpers, SemanticHash planning, and ProjectIndex remember/lookup.
 *
 * Move-only — no new C ABI. php-src analogy: Zend opcache file cache load/store
 * for a script image (Zend/zend_file_cache.c) separate from key identity hashing.
 */
trait CompileCacheArtifactFacade
{
    /** @see CompileCacheArtifactPersist::hasFreshArtifact() */
    public static function hasFreshArtifact(string $key, string $sourcePath, string $sourceCode): bool
    {
        return CompileCacheArtifactPersist::hasFreshArtifact($key, $sourcePath, $sourceCode);
    }

    /** @see CompileCacheArtifactPersist::tryRestoreArtifact() */
    public static function tryRestoreArtifact(
        string $key,
        string $outfile,
        string $sourcePath,
        string $sourceCode
    ): bool {
        return CompileCacheArtifactPersist::tryRestoreArtifact($key, $outfile, $sourcePath, $sourceCode);
    }

    /** @see CompileCacheArtifactPersist::tryRestoreArtifactByKey() */
    public static function tryRestoreArtifactByKey(string $key, string $outfile): bool
    {
        return CompileCacheArtifactPersist::tryRestoreArtifactByKey($key, $outfile);
    }

    /** @see CompileCacheArtifactPersist::saveArtifact() */
    public static function saveArtifact(string $key, string $outfile): void
    {
        CompileCacheArtifactPersist::saveArtifact($key, $outfile);
    }

    /** @see CompileCacheArtifactPersist::hasFreshObject() */
    public static function hasFreshObject(string $key, string $sourcePath, string $sourceCode): bool
    {
        return CompileCacheArtifactPersist::hasFreshObject($key, $sourcePath, $sourceCode);
    }

    /**
     * @return array{version: int, helper_slugs: list<string>}|null
     *
     * @see CompileCacheArtifactPersist::readLinkManifest()
     */
    public static function readLinkManifest(string $key): ?array
    {
        return CompileCacheArtifactPersist::readLinkManifest($key);
    }

    /**
     * @param list<string> $helperSlugs basenames under helper-runtime units/
     *
     * @see CompileCacheArtifactPersist::saveObject()
     */
    public static function saveObject(string $key, string $objectFile, array $helperSlugs): void
    {
        CompileCacheArtifactPersist::saveObject($key, $objectFile, $helperSlugs);
    }

    /** @see CompileCacheArtifactPersist::tryRestoreObjectAndLink() */
    public static function tryRestoreObjectAndLink(
        string $key,
        string $outfile,
        string $sourcePath,
        string $sourceCode
    ): bool {
        return CompileCacheArtifactPersist::tryRestoreObjectAndLink($key, $outfile, $sourcePath, $sourceCode);
    }
}
