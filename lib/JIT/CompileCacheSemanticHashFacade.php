<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Semantic-hash / edit-strip planning facade for AOT CompileCache (#36387).
 *
 * Thin public delegates onto {@see CompileCacheSemanticHash} /
 * {@see CompileCacheSemanticFileParts} so the hub keeps ratcheting under the
 * size-budget split-TU program. Distinct from KeyLayout path helpers,
 * ArtifactPersist mid-tier restore, and ProjectIndex remember/lookup.
 *
 * Move-only — no new C ABI. php-src analogy: Zend opcache invalidates a script
 * image when the source checksum changes (Zend/zend_accelerator_hash.c shape),
 * separate from the on-disk cache file load/store path.
 */
trait CompileCacheSemanticHashFacade
{
    /**
     * @param array<string, string> $previous
     * @param array<string, string> $current
     *
     * @return list<string>
     *
     * @see CompileCacheSemanticHash::diffMemberHashes()
     */
    public static function diffMemberHashes(array $previous, array $current): array
    {
        return CompileCacheSemanticHash::diffMemberHashes($previous, $current);
    }

    /**
     * SHA-256 of PHP tokens with comments and whitespace removed (#36387).
     *
     * @see CompileCacheSemanticHash::semanticFileHash()
     */
    public static function semanticFileHash(string $path): ?string
    {
        return CompileCacheSemanticHash::semanticFileHash($path);
    }

    /**
     * Split a PHP file into glue (non-function) + per-function semantic hashes (#36387).
     *
     * @return array{glue: string, functions: array<string, string>}|null
     *
     * @see CompileCacheSemanticHash::semanticFileParts()
     */
    public static function semanticFileParts(string $path): ?array
    {
        return CompileCacheSemanticHash::semanticFileParts($path);
    }

    /**
     * @param list<string> $memberPaths
     *
     * @return array{functions: array<string, array<string, string>>, glue: array<string, string>}
     *
     * @see CompileCacheSemanticHash::memberSemanticParts()
     */
    public static function memberSemanticParts(array $memberPaths): array
    {
        return CompileCacheSemanticHash::memberSemanticParts($memberPaths);
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
     * @see CompileCacheSemanticHash::diffFunctionsForStrip()
     */
    public static function diffFunctionsForStrip(
        ?array $previousFunctions,
        ?array $currentFunctions,
        ?array $previousGlue,
        ?array $currentGlue,
        array $stripMembers
    ): array {
        return CompileCacheSemanticHash::diffFunctionsForStrip(
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
     *
     * @see CompileCacheSemanticHash::memberSemanticHashes()
     */
    public static function memberSemanticHashes(array $memberPaths): array
    {
        return CompileCacheSemanticHash::memberSemanticHashes($memberPaths);
    }

    /**
     * Members that must strip LLVM bodies: byte-changed AND semantically changed (#36387).
     *
     * @param array<string, string>      $previousBytes
     * @param array<string, string>      $currentBytes
     * @param array<string, string>|null $previousSemantic
     * @param array<string, string>|null $currentSemantic
     *
     * @return list<string>
     *
     * @see CompileCacheSemanticHash::diffMembersForStrip()
     */
    public static function diffMembersForStrip(
        array $previousBytes,
        array $currentBytes,
        ?array $previousSemantic,
        ?array $currentSemantic
    ): array {
        return CompileCacheSemanticHash::diffMembersForStrip(
            $previousBytes,
            $currentBytes,
            $previousSemantic,
            $currentSemantic
        );
    }
}
