<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\AOT\HelperRuntimeCache;
use PHPCompiler\Block;
use PHPCompiler\Config;

require_once __DIR__.'/CompileCacheSemanticHash.php';
require_once __DIR__.'/CompileCachePartialEmitDemote.php';
require_once __DIR__.'/CompileCacheArtifactPersist.php';
require_once __DIR__.'/CompileCacheEditScaffold.php';

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
 * edit-scaffold restore/strip/rebind lives in {@see CompileCacheEditScaffold}
 * (#36387 one-file-edit Done-when / #36403 size-budget split-TU).
 */
final class CompileCache
{
    use CompileCacheEditScaffold;

    private const META_VERSION = 1;

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

    public static function cacheRoot(): string
    {
        $override = Config::getenv('PHP_COMPILER_CACHE_DIR');
        if (is_string($override) && '' !== $override) {
            return rtrim($override, '/');
        }

        return dirname(__DIR__, 2).'/.php-compiler-cache';
    }

    public static function computeKey(string $sourcePath, string $sourceCode): string
    {
        $resolved = realpath($sourcePath);
        $pathPart = false !== $resolved ? $resolved : $sourcePath;
        $mtime = is_file($pathPart) ? (string) filemtime($pathPart) : '0';

        return hash('sha256', implode("\0", [
            $pathPart,
            $mtime,
            strlen($sourceCode),
            hash('sha256', $sourceCode),
            self::fingerprint(),
        ]));
    }

    public static function entryDir(string $key): string
    {
        return self::cacheRoot().'/'.$key;
    }

    public static function bitcodePath(string $key): string
    {
        return self::entryDir($key).'/module.bc';
    }

    /**
     * AOT freshness marker when full-module bitcode cannot round-trip (#36387).
     */
    public static function stampPath(string $key): string
    {
        return self::entryDir($key).'/fresh.stamp';
    }

    /**
     * Linked AOT executable bytes for an unchanged-source rebuild (#36387 / #36199).
     *
     * Bitcode restore still re-runs loadJitContext + object emit + link (~5 s for hello).
     * Caching the final binary lets warm `phpc build` skip that path entirely.
     */
    public static function artifactPath(string $key): string
    {
        return self::entryDir($key).'/aot.bin';
    }

    /**
     * Emitted user-script object for mid-tier restore (#36387 / #36199).
     *
     * When `aot.bin` is missing but this `.o` is fresh, {@see tryRestoreObjectAndLink()}
     * skips LLVM Context / emitToFile and only re-runs the system link with the recorded
     * helper-runtime unit slugs.
     */
    public static function objectPath(string $key): string
    {
        return self::entryDir($key).'/aot.o';
    }

    /** Sidecar listing helper-runtime unit slugs needed to link {@see objectPath()}. */
    public static function linkManifestPath(string $key): string
    {
        return self::entryDir($key).'/link.json';
    }

    public static function metaPath(string $key): string
    {
        return self::entryDir($key).'/meta.json';
    }

    /**
     * @return array{version: int, fingerprint: string, exports: list<array{llvm: string, signature: string, scoped: string}>}|null
     */
    public static function readMeta(string $key): ?array
    {
        $path = self::metaPath($key);
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if (false === $raw) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        if ((int) ($decoded['version'] ?? 0) !== self::META_VERSION) {
            return null;
        }
        if (($decoded['fingerprint'] ?? '') !== self::fingerprint()) {
            return null;
        }
        if (!isset($decoded['exports']) || !is_array($decoded['exports'])) {
            return null;
        }

        return $decoded;
    }

    public static function isFresh(string $key, string $sourcePath, string $sourceCode): bool
    {
        if (!self::isEnabled()) {
            return false;
        }
        if (self::computeKey($sourcePath, $sourceCode) !== $key) {
            return false;
        }
        if (null === self::readMeta($key)) {
            return false;
        }

        // JIT: module.bc. AOT: fresh.stamp and/or module.bc (void*→i8* makes bitcode legal).
        // Artifact / object alone also count so mid-tier restore stays valid (#36387).
        return self::hasDurableMarker($key);
    }

    /** True when the cache entry has a durable on-disk marker for this key. */
    public static function hasDurableMarker(string $key): bool
    {
        if (is_file(self::stampPath($key))) {
            return true;
        }
        if (is_file(self::bitcodePath($key))) {
            return true;
        }
        if (is_file(self::artifactPath($key)) && filesize(self::artifactPath($key)) > 0) {
            return true;
        }

        return is_file(self::objectPath($key)) && filesize(self::objectPath($key)) > 0;
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

    public static function beginRecording(string $key): void
    {
        self::$recordingKey = $key;
        self::$recordingExports = [];
        self::$recordingUserSymbols = [];
        self::$recordingHelperSymbols = [];
        self::$recordingUserSymbolsByMember = [];
        self::$recordingUserSymbolsByFunction = [];
    }

    public static function setBundledSource(string $source): void
    {
        self::$bundledSource = $source;
    }

    /**
     * @param list<string> $paths
     */
    public static function setEditChangedMembers(array $paths): void
    {
        $clean = [];
        foreach ($paths as $path) {
            if (!is_string($path) || '' === $path) {
                continue;
            }
            $resolved = realpath($path);
            $clean[] = false !== $resolved ? $resolved : $path;
        }
        self::$editChangedMembers = array_values(array_unique($clean));
    }

    /** @return list<string> */
    public static function editChangedMembers(): array
    {
        return self::$editChangedMembers;
    }

    /**
     * @param array<string, array<string, true|int|string>> $pathToScoped
     */
    public static function setEditChangedFunctions(array $pathToScoped): void
    {
        $clean = [];
        foreach ($pathToScoped as $path => $scopedMap) {
            if (!is_string($path) || '' === $path || !is_array($scopedMap)) {
                continue;
            }
            $resolved = realpath($path);
            $key = false !== $resolved ? $resolved : $path;
            $funcs = [];
            foreach ($scopedMap as $scoped => $flag) {
                if (!is_string($scoped) || '' === $scoped) {
                    continue;
                }
                if (false === $flag || null === $flag) {
                    continue;
                }
                $funcs[strtolower($scoped)] = true;
            }
            if ([] !== $funcs) {
                $clean[$key] = $funcs;
            }
        }
        self::$editChangedFunctions = $clean;
    }

    /** @return array<string, array<string, true>> */
    public static function editChangedFunctions(): array
    {
        return self::$editChangedFunctions;
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


    public static function isRecording(): bool
    {
        return null !== self::$recordingExports;
    }

    public static function recordExport(string $llvmName, string $signature, Block $block): void
    {
        if (null === self::$recordingExports) {
            return;
        }
        self::$recordingExports[] = [
            'llvm' => $llvmName,
            'signature' => $signature,
            'scoped' => self::blockScopedName($block),
        ];
    }

    /** Record a user-TU LLVM symbol (not NestedJIT) for edit-scaffold stripping (#36387). */
    public static function recordUserLlvmSymbol(string $llvmName, ?\PHPCompiler\Block $block = null): void
    {
        if (null === self::$recordingUserSymbols || '' === $llvmName) {
            return;
        }
        self::$recordingUserSymbols[] = $llvmName;
        if (null === self::$recordingUserSymbolsByMember) {
            return;
        }
        $member = self::memberPathForBlock($block);
        if ('' === $member) {
            return;
        }
        if (!isset(self::$recordingUserSymbolsByMember[$member])) {
            self::$recordingUserSymbolsByMember[$member] = [];
        }
        self::$recordingUserSymbolsByMember[$member][] = $llvmName;

        if (null === self::$recordingUserSymbolsByFunction || null === $block || null === $block->func) {
            return;
        }
        $fname = $block->func->name;
        if (!is_string($fname) || '' === $fname || '{main}' === $fname || str_starts_with($fname, '{')) {
            return;
        }
        $scoped = $block->func->getScopedName();
        if (!is_string($scoped) || '' === $scoped) {
            return;
        }
        if (!isset(self::$recordingUserSymbolsByFunction[$member])) {
            self::$recordingUserSymbolsByFunction[$member] = [];
        }
        if (!isset(self::$recordingUserSymbolsByFunction[$member][$scoped])) {
            self::$recordingUserSymbolsByFunction[$member][$scoped] = [];
        }
        self::$recordingUserSymbolsByFunction[$member][$scoped][] = $llvmName;
    }

    private static function memberPathForBlock(?\PHPCompiler\Block $block): string
    {
        if (null === $block) {
            return '';
        }
        // Named user functions: prefer the project member that declares them. Bundled
        // opcode startLine often lands on the call site in the entry file after
        // SourceBundler concat, which swapped greeting→main.php (#36387).
        if (null !== $block->func) {
            $fname = $block->func->name;
            if (
                is_string($fname)
                && '' !== $fname
                && '{main}' !== $fname
                && !str_starts_with($fname, '{')
            ) {
                $declared = self::memberPathDeclaringFunction($fname);
                if ('' !== $declared) {
                    return $declared;
                }
            }
            if ('{main}' === $fname) {
                if (is_string(self::$projectEntry) && '' !== self::$projectEntry) {
                    return self::$projectEntry;
                }
            }
        }
        $line = 0;
        foreach ($block->opCodes as $op) {
            if (null !== $op->sourceLocation && $op->sourceLocation->startLine > 0) {
                $line = $op->sourceLocation->startLine;
                break;
            }
        }
        if (is_string(self::$bundledSource) && '' !== self::$bundledSource && $line > 0) {
            $mapped = \PHPCompiler\Web\SourceBundler::mapBundledLine(self::$bundledSource, $line);
            if (is_array($mapped) && isset($mapped[0]) && is_string($mapped[0]) && '' !== $mapped[0]) {
                $resolved = realpath($mapped[0]);

                return false !== $resolved ? $resolved : $mapped[0];
            }
        }
        $script = $block->scriptPath();
        if ('' === $script) {
            return '';
        }
        $resolved = realpath($script);

        return false !== $resolved ? $resolved : $script;
    }

    /**
     * Absolute path of the project member that declares `function $name` (#36387).
     */
    private static function memberPathDeclaringFunction(string $name): string
    {
        $members = self::$projectMembers ?? [];
        if ([] === $members || '' === $name) {
            return '';
        }
        $re = '/function\s+'.preg_quote($name, '/').'\s*\(/i';
        $hits = [];
        foreach ($members as $path) {
            if (!is_string($path) || !is_file($path)) {
                continue;
            }
            $src = @file_get_contents($path);
            if (!is_string($src) || !preg_match($re, $src)) {
                continue;
            }
            $resolved = realpath($path);
            $hits[] = false !== $resolved ? $resolved : $path;
        }
        if (1 === count($hits)) {
            return $hits[0];
        }

        return '';
    }

    /** Record NestedJIT helper logical→LLVM so edit scaffold can rebind without re-NestedJIT (#36387). */
    public static function recordHelperLogical(string $logicalLc, string $llvmName): void
    {
        if (null === self::$recordingHelperSymbols || '' === $logicalLc || '' === $llvmName) {
            return;
        }
        self::$recordingHelperSymbols[strtolower($logicalLc)] = $llvmName;
    }

    /**
     * @return bool true when bitcode was loaded and exports restored
     */
    public static function tryRestore(Context $context, Block $block, string $key): bool
    {
        $meta = self::readMeta($key);
        if (null === $meta) {
            return false;
        }
        $bcPath = self::bitcodePath($key);
        if (!is_file($bcPath)) {
            return false;
        }

        try {
            $context->replaceModuleFromBitcodeFile($bcPath);
        } catch (\Throwable $e) {
            return false;
        }

        SuperglobalInit::rebindGlobalsFromModule($context);
        self::restoreExports($context, $block, $meta['exports']);
        $context->rebindFunctionScopeFromModule();
        $context->rebindInitShutdownAfterModuleReplace();
        $context->refreshIntrinsicAfterModuleReplace();
        $context->syncIntrinsicBuilder();
        self::$skipModuleFuncCompile = true;
        self::$editScaffoldActive = false;

        return true;
    }

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

        return hash('sha256', implode("\0", $clean)."\0".self::fingerprint());
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
        return self::cacheRoot().'/projects/'.$projectId.'.json';
    }

    /**
     * Entry → member-path list so warm/edit boots skip Runtime include discovery (#36387).
     */
    public static function entryMembersPath(string $entryPath): string
    {
        $resolved = realpath($entryPath);
        $key = hash('sha256', false !== $resolved ? $resolved : $entryPath);

        return self::cacheRoot().'/projects/entry/'.$key.'.json';
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
        $dir = self::cacheRoot().'/projects/entry';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $payload = json_encode([
            'version' => 1,
            'fingerprint' => self::fingerprint(),
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
        if (($decoded['fingerprint'] ?? '') !== self::fingerprint()) {
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

    /**
     * @param array<string, string> $memberHashes
     */
    public static function rememberProject(string $projectId, string $key, array $memberHashes): void
    {
        if ('' === $projectId || '' === $key) {
            return;
        }
        $dir = self::cacheRoot().'/projects';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $payload = json_encode([
            'version' => 1,
            'key' => $key,
            'fingerprint' => self::fingerprint(),
            'members' => $memberHashes,
            // Comment/whitespace-stable hashes for strip planning (#36387).
            'semantic_members' => self::memberSemanticHashes(array_keys($memberHashes)),
            // Per-function + glue hashes so one-method edits keep sibling bodies (#36387).
            'semantic_parts' => self::memberSemanticParts(array_keys($memberHashes)),
            'updated_at' => gmdate('c'),
        ], JSON_PRETTY_PRINT);
        if (false === $payload) {
            return;
        }
        file_put_contents(self::projectIndexPath($projectId), $payload."\n");
        $entry = self::$projectEntry;
        if (is_string($entry) && '' !== $entry) {
            self::rememberEntryMembers($entry, array_keys($memberHashes));
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
        if (($decoded['fingerprint'] ?? '') !== self::fingerprint()) {
            return null;
        }
        $prevKey = $decoded['key'] ?? '';
        if (!is_string($prevKey) || '' === $prevKey) {
            return null;
        }
        if (!is_file(self::bitcodePath($prevKey))) {
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

    /**
     * User-script main LLVM function after {@see tryRestore()} (#36199).
     */
    public static function resolveRestoredMainFunction(Context $context, string $key): ?\PHPLLVM\Value\Function_
    {
        $meta = self::readMeta($key);
        if (null === $meta) {
            return null;
        }
        foreach ($meta['exports'] as $entry) {
            if (($entry['scoped'] ?? '') !== '{main}') {
                continue;
            }
            $llvm = (string) ($entry['llvm'] ?? '');
            if ('' === $llvm) {
                continue;
            }
            $func = $context->module->getNamedFunction($llvm);
            if ($func instanceof \PHPLLVM\Value\Function_) {
                return $func;
            }
        }

        return null;
    }

    public static function save(Context $context, string $key): void
    {
        if (null === self::$recordingExports) {
            return;
        }
        $dir = self::entryDir($key);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $lockPath = $dir.'/.lock';
        $lock = @fopen($lockPath, 'c+');
        if (false === $lock) {
            return;
        }
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);

            return;
        }

        try {
            $context->module->writeBitcodeToFile(self::bitcodePath($key));
            $payload = json_encode([
                'version' => self::META_VERSION,
                'fingerprint' => self::fingerprint(),
                'exports' => self::$recordingExports,
            ], JSON_PRETTY_PRINT);
            if (false !== $payload) {
                file_put_contents(self::metaPath($key), $payload);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * AOT cache entry: meta + {@see stampPath()} + optional round-trippable module.bc (#36387).
     *
     * void* previously made LLVMParseBitcode fail with Invalid type; opaque pointers now
     * lower as i8* so bitcode is durable. Warm rebuilds still prefer aot.bin / aot.o.
     */
    public static function saveAotStamp(string $key, ?Context $context = null): void
    {
        if (null === self::$recordingExports) {
            return;
        }
        $dir = self::entryDir($key);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $lockPath = $dir.'/.lock';
        $lock = @fopen($lockPath, 'c+');
        if (false === $lock) {
            return;
        }
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);

            return;
        }

        try {
            // Capture user main wrapper if present (added in compileToFile before this runs).
            if (null !== $context) {
                $main = $context->module->getNamedFunction('main');
                if ($main instanceof \PHPLLVM\Value\Function_) {
                    self::recordUserLlvmSymbol('main');
                }
            }
            $userSymbols = array_values(array_unique(self::$recordingUserSymbols ?? []));
            $helperSymbols = self::$recordingHelperSymbols ?? [];
            $functionLlvmSymbols = [];
            if (null !== $context && is_array($context->functionLlvmSymbols)) {
                foreach ($context->functionLlvmSymbols as $logical => $llvm) {
                    if (is_string($logical) && is_string($llvm) && '' !== $logical && '' !== $llvm) {
                        $functionLlvmSymbols[strtolower($logical)] = $llvm;
                    }
                }
            }
            $payload = json_encode([
                'version' => self::META_VERSION,
                'fingerprint' => self::fingerprint(),
                'exports' => self::$recordingExports,
                'user_symbols' => $userSymbols,
                'user_symbols_by_member' => self::$recordingUserSymbolsByMember ?? [],
                'user_symbols_by_function' => self::$recordingUserSymbolsByFunction ?? [],
                'helper_symbols' => $helperSymbols,
                // Full builtin/user logical→LLVM map so edit-scaffold can rebuild
                // Context::$functions without re-implement() (#36387).
                'function_llvm_symbols' => $functionLlvmSymbols,
                'aot_stamp' => true,
            ], JSON_PRETTY_PRINT);
            if (false !== $payload) {
                file_put_contents(self::metaPath($key), $payload);
            }
            file_put_contents(self::stampPath($key), "aot\n");
            if (null !== $context) {
                $context->module->writeBitcodeToFile(self::bitcodePath($key));
            }
            $members = self::projectMembers();
            if ([] !== $members) {
                self::rememberProject(
                    self::projectId($members),
                    $key,
                    self::memberHashes($members)
                );
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function finishRecording(): void
    {
        self::$recordingKey = null;
        self::$recordingExports = null;
        self::$recordingUserSymbols = null;
        self::$recordingHelperSymbols = null;
        self::$recordingUserSymbolsByMember = null;
        self::$recordingUserSymbolsByFunction = null;
        self::$editScaffoldByFunction = [];
        self::$bundledSource = null;
        self::$editChangedMembers = [];
        self::$editChangedFunctions = [];
        self::$keptUserSymbols = [];
        self::$strippedUserSymbols = [];
        self::$skipModuleFuncCompile = false;
        self::$editScaffoldActive = false;
        self::$editScaffoldPartial = false;
        self::$partialEmitBaseObject = null;
        self::$editScaffoldBitcodeBound = false;
        self::$pendingEditScaffoldKey = null;
        self::$projectMembers = null;
        self::$projectEntry = null;
    }

    /**
     * @param list<array{llvm?: string, signature?: string, scoped?: string}> $exports
     */
    private static function restoreExports(Context $context, Block $block, array $exports): void
    {
        $blocksByScoped = self::collectBlocksByScopedName($block);
        foreach ($exports as $entry) {
            $llvm = $entry['llvm'] ?? '';
            $signature = $entry['signature'] ?? '';
            $scoped = $entry['scoped'] ?? '';
            if ('' === $llvm || '' === $signature || '' === $scoped) {
                continue;
            }
            if (!isset($blocksByScoped[$scoped])) {
                continue;
            }
            $context->addExport($llvm, $signature, $blocksByScoped[$scoped]);
        }
    }

    /**
     * @return array<string, Block>
     */
    private static function collectBlocksByScopedName(Block $root): array
    {
        $map = [];
        $queue = [$root];
        while ([] !== $queue) {
            $current = array_shift($queue);
            if (null !== $current->func) {
                $map[$current->func->getScopedName()] = $current;
            } else {
                $map['{main}'] = $current;
            }
            foreach ($current->blocks as $child) {
                $queue[] = $child;
            }
        }

        return $map;
    }

    private static function blockScopedName(Block $block): string
    {
        if (null !== $block->func) {
            return $block->func->getScopedName();
        }

        return '{main}';
    }

    private static function fingerprint(): string
    {
        static $cached = null;
        if (null !== $cached) {
            return $cached;
        }

        $parts = [];
        $lock = dirname(__DIR__, 2).'/composer.lock';
        if (is_file($lock)) {
            $parts[] = hash_file('sha256', $lock) ?: '';
        }
        $parts[] = HelperRuntimeCache::llvmIdentityToken();
        $parts[] = hash_file('sha256', __DIR__.'/../JIT/Context.php') ?: '';
        $parts[] = hash_file('sha256', __DIR__.'/../JIT.php') ?: '';
        // Hashtable string-key DJB index / unset (#36191 / #36732) — artifact restore
        // must not keep pre-fix binaries when only Type/HashTable.php changed.
        $parts[] = hash_file('sha256', __DIR__.'/Builtin/Type/HashTable.php') ?: '';
        $parts[] = hash_file('sha256', __DIR__.'/Builtin/AttributeRegistryLowering.php') ?: '';
        $parts[] = hash_file('sha256', __DIR__.'/../Runtime.php') ?: '';
        $parts[] = LazyBuiltins::fingerprintSegment();
        $parts[] = HelperRuntimeCache::coreFingerprint();
        $parts[] = HelperRuntimeCache::cacheKeySegment();
        foreach (['PHP_COMPILER_AOT_USER_SCRIPT', 'PHP_COMPILER_HELPER_RUNTIME_O'] as $envKey) {
            $flag = getenv($envKey);
            $parts[] = $envKey.'='.(false === $flag ? '' : $flag);
        }

        $cached = hash('sha256', implode("\0", $parts));

        return $cached;
    }
}
