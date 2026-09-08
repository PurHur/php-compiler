<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Multi-file project index + edit-scaffold key lookup for AOT CompileCache (#36387 / #36199).
 *
 * Extracted from {@see CompileCache} so one-file-edit warm discovery (project identity,
 * byte hashes, prior-key scaffold lookup) stays a separate TU while the hub keeps thin
 * public delegates used by bin/compile.php and unit tests. Entry→members durability
 * lives in {@see CompileCacheProjectEntryMembers}.
 *
 * Durability keys on {@see CompileCache::compilerFingerprint()} so a compiler/layout
 * change invalidates prior project JSON without restamping.
 *
 * No new C ABI. php-src analogy: Zend opcache / file cache keys a script identity and
 * reuses a prior compiled image when the member set is known (Zend/zend_file_cache.c /
 * Zend/zend_accelerator_hash.c shape) — here the “image” is a prior cache key + bitcode.
 */
final class CompileCacheProjectIndex
{
    /**
     * Project identity = sorted member realpaths (content-independent) (#36387).
     *
     * @param list<string> $memberPaths
     */
    public static function projectId(array $memberPaths): string
    {
        $clean = [];
        foreach ($memberPaths as $path) {
            if (!is_string($path) || '' === $path) {
                continue;
            }
            $resolved = realpath($path);
            $clean[] = false !== $resolved ? $resolved : $path;
        }
        $clean = array_values(array_unique($clean));
        sort($clean);

        return hash('sha256', implode("\0", $clean)."\0".CompileCache::compilerFingerprint());
    }

    /**
     * @param list<string> $memberPaths
     *
     * @return array<string, string> path → sha256 of file bytes
     */
    public static function memberHashes(array $memberPaths): array
    {
        $out = [];
        foreach ($memberPaths as $path) {
            if (!is_string($path) || !is_file($path)) {
                continue;
            }
            $resolved = realpath($path) ?: $path;
            $hash = hash_file('sha256', $resolved);
            if (is_string($hash)) {
                $out[$resolved] = $hash;
            }
        }
        ksort($out);

        return $out;
    }

    public static function projectIndexPath(string $projectId): string
    {
        return CompileCache::cacheRoot().'/projects/'.$projectId.'.json';
    }

    /**
     * @param array<string, string> $memberHashes
     */
    public static function rememberProject(string $projectId, string $key, array $memberHashes): void
    {
        if ('' === $projectId || '' === $key) {
            return;
        }
        $dir = CompileCache::cacheRoot().'/projects';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $payload = json_encode([
            'version' => 1,
            'key' => $key,
            'fingerprint' => CompileCache::compilerFingerprint(),
            'members' => $memberHashes,
            // Comment/whitespace-stable hashes for strip planning (#36387).
            'semantic_members' => CompileCache::memberSemanticHashes(array_keys($memberHashes)),
            // Per-function + glue hashes so one-method edits keep sibling bodies (#36387).
            'semantic_parts' => CompileCache::memberSemanticParts(array_keys($memberHashes)),
            'updated_at' => gmdate('c'),
        ], JSON_PRETTY_PRINT);
        if (false === $payload) {
            return;
        }
        file_put_contents(self::projectIndexPath($projectId), $payload."\n");
        $entry = CompileCache::projectEntry();
        if (is_string($entry) && '' !== $entry) {
            CompileCacheProjectEntryMembers::rememberEntryMembers($entry, array_keys($memberHashes));
        }
    }

    /**
     * Prior cache key for this project when at least one member changed (#36387).
     *
     * @param array<string, string> $memberHashes
     */
    public static function findEditScaffoldKey(string $projectId, array $memberHashes): ?string
    {
        $path = self::projectIndexPath($projectId);
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if (false === $raw) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || (int) ($decoded['version'] ?? 0) !== 1) {
            return null;
        }
        if (($decoded['fingerprint'] ?? '') !== CompileCache::compilerFingerprint()) {
            return null;
        }
        $prevKey = $decoded['key'] ?? '';
        if (!is_string($prevKey) || '' === $prevKey) {
            return null;
        }
        if (!is_file(CompileCache::bitcodePath($prevKey))) {
            return null;
        }
        $prevMembers = $decoded['members'] ?? null;
        if (!is_array($prevMembers) || [] === $prevMembers) {
            return null;
        }
        // Identical members → exact warm path should have hit already; no scaffold.
        if ($prevMembers === $memberHashes) {
            return null;
        }
        // Require same path set (add/remove file → full rebuild).
        if (array_keys($prevMembers) !== array_keys($memberHashes)) {
            return null;
        }

        return $prevKey;
    }
}
