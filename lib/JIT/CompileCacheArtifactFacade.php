<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Artifact / object mid-tier warm restore facade for AOT CompileCache (#36387).
 *
 * Thin public delegates onto {@see CompileCacheArtifactPersist} (`aot.bin`) and
 * {@see CompileCacheObjectLinkPersist} (`.o` + helper link manifest). Distinct from
 * KeyLayout, SemanticHash, and ProjectIndex facades.
 *
 * Move-only — no new C ABI. php-src: Zend/zend_file_cache.c load/store shape.
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

    /** @see CompileCacheObjectLinkPersist::hasFreshObject() */
    public static function hasFreshObject(string $key, string $sourcePath, string $sourceCode): bool
    {
        return CompileCacheObjectLinkPersist::hasFreshObject($key, $sourcePath, $sourceCode);
    }

    /**
     * @return array{version: int, helper_slugs: list<string>}|null
     *
     * @see CompileCacheObjectLinkPersist::readLinkManifest()
     */
    public static function readLinkManifest(string $key): ?array
    {
        return CompileCacheObjectLinkPersist::readLinkManifest($key);
    }

    /**
     * @param list<string> $helperSlugs basenames under helper-runtime units/
     *
     * @see CompileCacheObjectLinkPersist::saveObject()
     */
    public static function saveObject(string $key, string $objectFile, array $helperSlugs): void
    {
        CompileCacheObjectLinkPersist::saveObject($key, $objectFile, $helperSlugs);
    }

    /** @see CompileCacheObjectLinkPersist::tryRestoreObjectAndLink() */
    public static function tryRestoreObjectAndLink(
        string $key,
        string $outfile,
        string $sourcePath,
        string $sourceCode
    ): bool {
        return CompileCacheObjectLinkPersist::tryRestoreObjectAndLink($key, $outfile, $sourcePath, $sourceCode);
    }
}
