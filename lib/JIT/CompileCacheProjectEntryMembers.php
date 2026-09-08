<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Entry → member-path list for AOT CompileCache warm/edit boots (#36387 / #36199).
 *
 * Extracted from {@see CompileCacheProjectIndex} so entry-local discovery cache
 * (skip Runtime include walk when entry bytes are unchanged) stays a separate TU
 * from project identity / member hashes / edit-scaffold key lookup.
 *
 * Durability keys on {@see CompileCache::compilerFingerprint()} so a compiler/layout
 * change invalidates prior entry JSON without restamping.
 *
 * No new C ABI. php-src analogy: Zend opcache / file cache remembers which scripts
 * belong to a compiled image keyed by script identity (Zend/zend_file_cache.c /
 * Zend/zend_accelerator_hash.c) — here the “image” is a prior member-path list for
 * one entry file.
 */
final class CompileCacheProjectEntryMembers
{
    /**
     * Entry → member-path list so warm/edit boots skip Runtime include discovery (#36387).
     */
    public static function entryMembersPath(string $entryPath): string
    {
        $resolved = realpath($entryPath);
        $key = hash('sha256', false !== $resolved ? $resolved : $entryPath);

        return CompileCache::cacheRoot().'/projects/entry/'.$key.'.json';
    }

    /**
     * @param list<string> $memberPaths
     */
    public static function rememberEntryMembers(string $entryPath, array $memberPaths): void
    {
        if ('' === $entryPath || [] === $memberPaths || !is_file($entryPath)) {
            return;
        }
        $resolved = realpath($entryPath);
        $entry = false !== $resolved ? $resolved : $entryPath;
        $entryHash = hash_file('sha256', $entry);
        if (!is_string($entryHash)) {
            return;
        }
        $clean = [];
        foreach ($memberPaths as $path) {
            if (!is_string($path) || '' === $path) {
                continue;
            }
            $r = realpath($path);
            $clean[] = false !== $r ? $r : $path;
        }
        $clean = array_values(array_unique($clean));
        if ([] === $clean) {
            return;
        }
        $dir = CompileCache::cacheRoot().'/projects/entry';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $payload = json_encode([
            'version' => 1,
            'fingerprint' => CompileCache::compilerFingerprint(),
            'entry' => $entry,
            'entry_hash' => $entryHash,
            'members' => $clean,
            'updated_at' => gmdate('c'),
        ], JSON_PRETTY_PRINT);
        if (false === $payload) {
            return;
        }
        file_put_contents(self::entryMembersPath($entry), $payload."\n");
    }

    /**
     * Prior member list for this entry when the entry bytes are unchanged (#36387).
     *
     * @return list<string>|null
     */
    public static function lookupEntryMembers(string $entryPath): ?array
    {
        if ('' === $entryPath || !is_file($entryPath)) {
            return null;
        }
        $path = self::entryMembersPath($entryPath);
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
        $entryHash = hash_file('sha256', $entryPath);
        if (!is_string($entryHash) || ($decoded['entry_hash'] ?? null) !== $entryHash) {
            // Entry changed — may have gained/lost requires; force rediscovery.
            return null;
        }
        $members = $decoded['members'] ?? null;
        if (!is_array($members) || [] === $members) {
            return null;
        }
        $out = [];
        foreach ($members as $member) {
            if (!is_string($member) || '' === $member || !is_file($member)) {
                return null;
            }
            $r = realpath($member);
            $out[] = false !== $r ? $r : $member;
        }

        return array_values(array_unique($out));
    }
}
