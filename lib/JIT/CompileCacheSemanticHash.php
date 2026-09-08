<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

require_once __DIR__.'/CompileCacheSemanticFileParts.php';

/**
 * Semantic file/member hashing for AOT edit-scaffold strip plans (#36387 / #36199).
 *
 * Extracted from {@see CompileCache} so one-file-edit ≤25%-of-cold work stays a
 * separate TU (split-TU / compile-cache iterability) while CompileCache keeps thin
 * delegates for the public API used by bin/compile.php and unit tests.
 *
 * Comment/whitespace-only edits still byte-invalidate the project cache key but
 * do not put the member on the strip list — matching the Done-when path measured
 * by bench-gate. Per-function hashes live in {@see CompileCacheSemanticFileParts}.
 */
final class CompileCacheSemanticHash
{
    /**
     * @param array<string, string> $previous
     * @param array<string, string> $current
     *
     * @return list<string>
     */
    public static function diffMemberHashes(array $previous, array $current): array
    {
        $changed = [];
        foreach ($current as $path => $hash) {
            if (!is_string($path) || !is_string($hash)) {
                continue;
            }
            if (($previous[$path] ?? null) !== $hash) {
                $changed[] = $path;
            }
        }

        return $changed;
    }

    /**
     * SHA-256 of PHP tokens with comments and whitespace removed (#36387).
     *
     * Used so a comment-only (or whitespace-only) edit of Router.php still
     * byte-invalidates the project cache key / edit scaffold, but does not put
     * Router on the strip list — kept bodies + delta demote then match the
     * config-only ≤25% path that the Done-when measures via bench-gate.
     */
    public static function semanticFileHash(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $src = file_get_contents($path);
        if (false === $src) {
            return null;
        }
        if ('' === $src) {
            return hash('sha256', '');
        }
        $tokens = @token_get_all($src);
        if (!\is_array($tokens) || [] === $tokens) {
            return hash('sha256', $src);
        }
        $buf = '';
        foreach ($tokens as $token) {
            if (\is_array($token)) {
                $id = $token[0];
                if (T_COMMENT === $id || T_DOC_COMMENT === $id || T_WHITESPACE === $id) {
                    continue;
                }
                $buf .= $token[1];
            } else {
                $buf .= $token;
            }
        }

        return hash('sha256', $buf);
    }

    /**
     * Split a PHP file into glue (non-function) + per-function semantic hashes (#36387).
     *
     * @return array{glue: string, functions: array<string, string>}|null
     *
     * @see CompileCacheSemanticFileParts::semanticFileParts()
     */
    public static function semanticFileParts(string $path): ?array
    {
        return CompileCacheSemanticFileParts::semanticFileParts($path);
    }

    /**
     * @param list<string> $memberPaths
     *
     * @return array{functions: array<string, array<string, string>>, glue: array<string, string>}
     *
     * @see CompileCacheSemanticFileParts::memberSemanticParts()
     */
    public static function memberSemanticParts(array $memberPaths): array
    {
        return CompileCacheSemanticFileParts::memberSemanticParts($memberPaths);
    }

    /**
     * Per-function strip plan for members that already failed the file-level semantic check.
     *
     * @param array<string, array<string, string>>|null $previousFunctions
     * @param array<string, array<string, string>>|null $currentFunctions
     * @param array<string, string>|null               $previousGlue
     * @param array<string, string>|null               $currentGlue
     * @param list<string>                             $stripMembers
     *
     * @return array<string, array<string, true>>
     *
     * @see CompileCacheSemanticFileParts::diffFunctionsForStrip()
     */
    public static function diffFunctionsForStrip(
        ?array $previousFunctions,
        ?array $currentFunctions,
        ?array $previousGlue,
        ?array $currentGlue,
        array $stripMembers
    ): array {
        return CompileCacheSemanticFileParts::diffFunctionsForStrip(
            $previousFunctions,
            $currentFunctions,
            $previousGlue,
            $currentGlue,
            $stripMembers
        );
    }

    /**
     * @param list<string> $memberPaths
     *
     * @return array<string, string> path → semantic sha256
     */
    public static function memberSemanticHashes(array $memberPaths): array
    {
        $out = [];
        foreach ($memberPaths as $path) {
            if (!is_string($path) || !is_file($path)) {
                continue;
            }
            $resolved = realpath($path) ?: $path;
            $hash = self::semanticFileHash($resolved);
            if (is_string($hash)) {
                $out[$resolved] = $hash;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Members that must strip LLVM bodies: byte-changed AND semantically changed (#36387).
     *
     * Falls back to byte-only diff when the prior project index lacks semantic_members
     * (caches written before this slice).
     *
     * @param array<string, string>      $previousBytes
     * @param array<string, string>      $currentBytes
     * @param array<string, string>|null $previousSemantic
     * @param array<string, string>|null $currentSemantic
     *
     * @return list<string>
     */
    public static function diffMembersForStrip(
        array $previousBytes,
        array $currentBytes,
        ?array $previousSemantic,
        ?array $currentSemantic
    ): array {
        $byteChanged = self::diffMemberHashes($previousBytes, $currentBytes);
        if (
            null === $previousSemantic
            || [] === $previousSemantic
            || null === $currentSemantic
            || [] === $currentSemantic
        ) {
            return $byteChanged;
        }
        $strip = [];
        foreach ($byteChanged as $path) {
            if (!is_string($path) || '' === $path) {
                continue;
            }
            $prev = $previousSemantic[$path] ?? null;
            $curr = $currentSemantic[$path] ?? null;
            if (!is_string($prev) || !is_string($curr) || $prev !== $curr) {
                $strip[] = $path;
            } else {
                \PHPCompiler\AOT\BuildTiming::note('edit_scaffold_semantic_keep', 1.0);
            }
        }

        return $strip;
    }
}
