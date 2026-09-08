<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Project index / entry→members facade for AOT CompileCache (#36387).
 *
 * Thin public delegates onto {@see CompileCacheProjectIndex} so the hub keeps
 * ratcheting under the size-budget split-TU program. Distinct from ProjectMembers
 * (in-memory member list), KeyLayout path helpers, SemanticHashFacade planning,
 * and ArtifactPersist mid-tier restore.
 *
 * Move-only — no new C ABI. php-src analogy: Zend opcache project/script identity
 * hashing and accelerator hash tables (Zend/zend_accelerator_hash.c) separate from
 * per-file cache payload load/store.
 */
trait CompileCacheProjectIndexFacade
{
    /**
     * Project identity = sorted member realpaths (content-independent) (#36387).
     *
     * @param list<string> $memberPaths
     *
     * @see CompileCacheProjectIndex::projectId()
     */
    public static function projectId(array $memberPaths): string
    {
        return CompileCacheProjectIndex::projectId($memberPaths);
    }

    /**
     * @param list<string> $memberPaths
     *
     * @return array<string, string> path → sha256 of file bytes
     *
     * @see CompileCacheProjectIndex::memberHashes()
     */
    public static function memberHashes(array $memberPaths): array
    {
        return CompileCacheProjectIndex::memberHashes($memberPaths);
    }

    /** @see CompileCacheProjectIndex::projectIndexPath() */
    public static function projectIndexPath(string $projectId): string
    {
        return CompileCacheProjectIndex::projectIndexPath($projectId);
    }

    /**
     * Entry → member-path list so warm/edit boots skip Runtime include discovery (#36387).
     *
     * @see CompileCacheProjectIndex::entryMembersPath()
     */
    public static function entryMembersPath(string $entryPath): string
    {
        return CompileCacheProjectIndex::entryMembersPath($entryPath);
    }

    /**
     * @param list<string> $memberPaths
     *
     * @see CompileCacheProjectIndex::rememberEntryMembers()
     */
    public static function rememberEntryMembers(string $entryPath, array $memberPaths): void
    {
        CompileCacheProjectIndex::rememberEntryMembers($entryPath, $memberPaths);
    }

    /**
     * Prior member list for this entry when the entry bytes are unchanged (#36387).
     *
     * @return list<string>|null
     *
     * @see CompileCacheProjectIndex::lookupEntryMembers()
     */
    public static function lookupEntryMembers(string $entryPath): ?array
    {
        return CompileCacheProjectIndex::lookupEntryMembers($entryPath);
    }

    /**
     * @param array<string, string> $memberHashes
     *
     * @see CompileCacheProjectIndex::rememberProject()
     */
    public static function rememberProject(string $projectId, string $key, array $memberHashes): void
    {
        CompileCacheProjectIndex::rememberProject($projectId, $key, $memberHashes);
    }

    /**
     * Prior cache key for this project when at least one member changed (#36387).
     *
     * @param array<string, string> $memberHashes
     *
     * @see CompileCacheProjectIndex::findEditScaffoldKey()
     */
    public static function findEditScaffoldKey(string $projectId, array $memberHashes): ?string
    {
        return CompileCacheProjectIndex::findEditScaffoldKey($projectId, $memberHashes);
    }
}
