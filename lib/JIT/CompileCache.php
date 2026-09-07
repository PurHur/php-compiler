<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\Config;

require_once __DIR__.'/CompileCacheSemanticHash.php';
require_once __DIR__.'/CompileCachePartialEmitDemote.php';
require_once __DIR__.'/CompileCacheArtifactPersist.php';
require_once __DIR__.'/CompileCacheEditScaffold.php';
require_once __DIR__.'/CompileCacheProjectIndex.php';
require_once __DIR__.'/CompileCacheKeyLayout.php';
require_once __DIR__.'/CompileCacheBitcodePersist.php';
require_once __DIR__.'/CompileCacheRecording.php';

/**
 * On-disk MCJIT bitcode cache (issue #153).
 *
 * Persists verified LLVM bitcode keyed by source bytes + compiler fingerprint so a
 * second `bin/jit.php` process can skip LLVM IR lowering when inputs are unchanged.
 *
 * AOT warm rebuilds use {@see artifactPath()} / {@see objectPath()} for the fast path.
 * Full-module {@see bitcodePath()} also round-trips once void* lowers as i8* (#36387).
 *
 * Semantic hash / edit-strip planning lives in {@see CompileCacheSemanticHash};
 * partial-emit demote lives in {@see CompileCachePartialEmitDemote};
 * linked-binary / user-object mid-tier warm restore lives in {@see CompileCacheArtifactPersist};
 * edit-scaffold restore/strip/rebind lives in {@see CompileCacheEditScaffold};
 * multi-file project index / entry→members map lives in {@see CompileCacheProjectIndex};
 * cache-entry paths / freshness / fingerprint live in {@see CompileCacheKeyLayout};
 * MCJIT bitcode restore/persist lives in {@see CompileCacheBitcodePersist};
 * cold-emit recording / symbol membership maps live in {@see CompileCacheRecording}
 * (#36387 one-file-edit Done-when / #36403 size-budget split-TU).
 */
final class CompileCache
{
    use CompileCacheEditScaffold;
    use CompileCacheBitcodePersist;
    use CompileCacheRecording;
    /** @var list<array{llvm: string, signature: string, scoped: string}>|null */
    private static ?array $recordingExports = null;

    /** @var list<string>|null LLVM names lowered outside NestedJIT (user TU) (#36387). */
    private static ?array $recordingUserSymbols = null;

    /** @var array<string, string>|null logical lc → LLVM name for NestedJIT helpers (#36387). */
    private static ?array $recordingHelperSymbols = null;

    /** @var list<string>|null absolute member paths for multi-file project index (#36387). */
    private static ?array $projectMembers = null;

    /** Absolute entry script path (before setProjectMembers sort) (#36387). */
    private static ?string $projectEntry = null;

    private static ?string $recordingKey = null;

    private static bool $skipModuleFuncCompile = false;

    /** True after {@see tryRestoreEditScaffold()} — helpers kept, user symbols stripped. */
    private static bool $editScaffoldActive = false;

    /** True after Context parsed prior module.bc before namedStructType (#36387). */
    private static bool $editScaffoldBitcodeBound = false;

    /**
     * Prior cache key armed before {@see Context} construct so defineBuiltins can skip
     * implement() (Values would dangle after module replace) (#36387).
     */
    private static ?string $pendingEditScaffoldKey = null;

    /** @var array<string, list<string>>|null member path → LLVM names (#36387) */
    private static ?array $recordingUserSymbolsByMember = null;

    /**
     * member path → scoped name (Class::method or function) → LLVM names (#36387).
     *
     * @var array<string, array<string, list<string>>>|null
     */
    private static ?array $recordingUserSymbolsByFunction = null;

    /**
     * Loaded from prior AOT meta for keep-path planning (#36387).
     *
     * @var array<string, array<string, list<string>>>
     */
    private static array $editScaffoldByFunction = [];

    private static ?string $bundledSource = null;

    /**
     * Absolute paths whose *semantic* content changed vs the scaffold project index
     * (comments/whitespace-only edits do not strip that member's LLVM bodies) (#36387).
     *
     * @var list<string>
     */
    private static array $editChangedMembers = [];

    /**
     * Within a semantically-changed member: scoped names (lc) whose bodies changed.
     * Absent path ⇒ full member strip; non-empty ⇒ keep sibling functions (#36387).
     *
     * @var array<string, array<string, true>>
     */
    private static array $editChangedFunctions = [];

    /**
     * LLVM names of user symbols left in the module after edit-scaffold partial strip (#36387).
     *
     * @var array<string, true>
     */
    private static array $keptUserSymbols = [];
    /**
     * User LLVM names renamed to `*.stale` during edit-scaffold strip (#36387).
     *
     * @var list<string>
     */
    private static array $strippedUserSymbols = [];

    /** True when edit-scaffold kept at least one unchanged user body (#36387). */
    private static bool $editScaffoldPartial = false;

    /**
     * Prior cold `aot.o` linked after a demoted delta emit on partial keep (#36387).
     *
     * Delta object is listed first; `-z muldefs` keeps rebuilt symbols from the delta
     * and everything else (runtime + kept user bodies) from this base object.
     */
    private static ?string $partialEmitBaseObject = null;

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

    public static function shouldSkipModuleFuncCompile(): bool
    {
        return self::$skipModuleFuncCompile;
    }

    public static function isEditScaffoldActive(): bool
    {
        return self::$editScaffoldActive;
    }

    /** True when edit-scaffold kept unchanged member bodies (#36387). */
    public static function isEditScaffoldPartial(): bool
    {
        return self::$editScaffoldPartial;
    }

    /** True when edit-scaffold left this user LLVM body in the module (#36387). */
    public static function isKeptUserSymbol(string $llvmName): bool
    {
        return '' !== $llvmName && isset(self::$keptUserSymbols[$llvmName]);
    }

    /**
     * Prior `aot.o` for partial delta link, or null when full emit is required (#36387).
     */
    public static function peekPartialEmitBaseObject(): ?string
    {
        $path = self::$partialEmitBaseObject;
        if (!is_string($path) || '' === $path || !is_file($path) || filesize($path) < 1) {
            return null;
        }

        return $path;
    }

    /**
     * Consume the base object path once (Linker inserts it after the delta `.o`) (#36387).
     */
    public static function consumePartialEmitBaseObject(): ?string
    {
        $path = self::peekPartialEmitBaseObject();
        self::$partialEmitBaseObject = null;

        return $path;
    }

    /**
     * Before TargetMachine emit on partial keep: drop bodies that already exist in the
     * prior `aot.o`, leaving declarations (#36387).
     *
     * @see CompileCachePartialEmitDemote::demoteBodiesForPartialObjectEmit()
     *
     * @return int number of functions demoted to declarations
     */
    public static function demoteBodiesForPartialObjectEmit(Context $context): int
    {
        return CompileCachePartialEmitDemote::demoteBodiesForPartialObjectEmit(
            $context,
            self::peekPartialEmitBaseObject(),
            self::$editScaffoldPartial,
            self::$strippedUserSymbols,
            self::$keptUserSymbols
        );
    }

    public static function isEditScaffoldBitcodeBound(): bool
    {
        return self::$editScaffoldBitcodeBound;
    }

    /**
     * Context parsed prior module.bc before CreateNamed — register() may early-return (#36387).
     */
    public static function markEditScaffoldBitcodeBound(): void
    {
        self::$editScaffoldBitcodeBound = true;
        self::$editScaffoldActive = true;
        self::$skipModuleFuncCompile = true;
    }

    /**
     * True when prior cache entry has module.bc + user_symbols (safe to thin-boot) (#36387).
     */
    public static function canUseEditScaffold(string $previousKey): bool
    {
        if ('' === $previousKey || !is_file(self::bitcodePath($previousKey))) {
            return false;
        }
        $raw = json_decode((string) file_get_contents(self::metaPath($previousKey)), true);
        if (!is_array($raw)) {
            return false;
        }
        $user = $raw['user_symbols'] ?? null;
        if (!is_array($user) || [] === $user) {
            return false;
        }

        return null !== self::readLinkManifest($previousKey);
    }

    /**
     * Arm edit-scaffold before Context construct (#36387).
     *
     * Thin boot loads prior module.bc first, then {@see Context::seedCoreTypesFromModuleForEditScaffold()}
     * + type register early-returns bind PHP-side maps without CreateNamed collisions.
     */
    public static function armEditScaffold(string $previousKey): void
    {
        if ('' === $previousKey) {
            return;
        }
        self::$pendingEditScaffoldKey = $previousKey;
    }

    public static function pendingEditScaffoldKey(): ?string
    {
        return self::$pendingEditScaffoldKey;
    }

    public static function takePendingEditScaffoldKey(): ?string
    {
        $key = self::$pendingEditScaffoldKey;
        self::$pendingEditScaffoldKey = null;

        return $key;
    }

    /**
     * True while Context should register decls/types only (no implement IR) (#36387).
     *
     * Pending alone is not enough: {@see Context::tryBindEditScaffoldBitcodeBeforeBuiltins()}
     * may fail to load module.bc while {@see armEditScaffold()} left a pending key. Skipping
     * {@see SuperglobalInit::initialize()} in that case leaves {@see SuperglobalInit::$globals}
     * empty and Slim/Composer rebuilds throw "Superglobal not initialized for JIT: _SERVER"
     * (#36382). Only skip after thin-boot bound the prior module (or restore completed).
     */
    public static function shouldSkipBuiltinImplement(): bool
    {
        return self::$editScaffoldActive || self::$editScaffoldBitcodeBound;
    }

    /**
     * Record absolute source paths that make up a multi-file AOT project (#36387).
     *
     * @param list<string> $absolutePaths entry + includes (pre-bundle)
     */
    public static function setProjectMembers(array $absolutePaths): void
    {
        $clean = [];
        foreach ($absolutePaths as $path) {
            if (!is_string($path) || '' === $path) {
                continue;
            }
            $resolved = realpath($path);
            $clean[] = false !== $resolved ? $resolved : $path;
        }
        // First path is the compile entry (compile.php merges entry + includes) (#36387).
        self::$projectEntry = $clean[0] ?? null;
        $clean = array_values(array_unique($clean));
        sort($clean);
        self::$projectMembers = $clean;
    }

    /** @return list<string> */
    public static function projectMembers(): array
    {
        return self::$projectMembers ?? [];
    }

    /** Compile entry path captured by {@see setProjectMembers()} (#36387). */
    public static function projectEntry(): ?string
    {
        return self::$projectEntry;
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
