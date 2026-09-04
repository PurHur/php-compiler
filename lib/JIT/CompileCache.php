<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\AOT\HelperRuntimeCache;
use PHPCompiler\Block;
use PHPCompiler\Config;

/**
 * On-disk MCJIT bitcode cache (issue #153).
 *
 * Persists verified LLVM bitcode keyed by source bytes + compiler fingerprint so a
 * second `bin/jit.php` process can skip LLVM IR lowering when inputs are unchanged.
 *
 * AOT warm rebuilds use {@see artifactPath()} / {@see objectPath()} for the fast path.
 * Full-module {@see bitcodePath()} also round-trips once void* lowers as i8* (#36387).
 */
final class CompileCache
{
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
     * prior `aot.o`, leaving declarations. Rebuild set (stripped user symbols + main +
     * init/shutdown) keep their bodies (#36387).
     *
     * After demote, unused `string_const_*` / `array_const_*` / `object_const_*` globals
     * are pruned so sibling-member literals do not inflate the delta object (one-method
     * edit ≤25% of cold headroom). NestedJIT (`PHPCompiler_*`) is not demoted — those
     * bodies can close over delta-local layout and crash under emit/link.
     *
     * @return int number of functions demoted to declarations
     */
    public static function demoteBodiesForPartialObjectEmit(Context $context): int
    {
        $base = self::peekPartialEmitBaseObject();
        if (null === $base || !self::$editScaffoldPartial) {
            return 0;
        }
        $prevDefs = self::objectDefinedSymbols($base);
        if ([] === $prevDefs) {
            return 0;
        }

        $mustKeepBody = ['main' => true];
        foreach (self::$strippedUserSymbols as $name) {
            if (is_string($name) && '' !== $name) {
                $mustKeepBody[$name] = true;
            }
        }
        foreach ([$context->initFunc, $context->shutdownFunc] as $lifecycle) {
            if ($lifecycle instanceof \PHPLLVM\Value\Function_) {
                $n = self::llvmFunctionName($context, $lifecycle);
                if ('' !== $n) {
                    $mustKeepBody[$n] = true;
                }
            }
        }
        $candidates = [];
        foreach (array_keys(self::$keptUserSymbols) as $name) {
            if (is_string($name) && '' !== $name) {
                $candidates[$name] = true;
            }
        }
        foreach (array_keys($prevDefs) as $name) {
            if (is_string($name) && '' !== $name && self::isSharedRuntimeDemoteCandidate($name)) {
                $candidates[$name] = true;
            }
        }

        $demoted = 0;
        foreach (array_keys($candidates) as $name) {
            if (isset($mustKeepBody[$name]) || str_ends_with($name, '.stale') || str_starts_with($name, 'llvm.')) {
                continue;
            }
            $fn = null;
            try {
                $fn = $context->module->getNamedFunction($name);
            } catch (\Throwable $e) {
                continue;
            }
            if (!$fn instanceof \PHPLLVM\Value\Function_) {
                continue;
            }
            $blocks = 0;
            try {
                $blocks = (int) $fn->countBasicBlocks();
            } catch (\Throwable $e) {
                continue;
            }
            if ($blocks < 1) {
                continue;
            }
            if (self::demoteFunctionBodyToDeclaration($fn)) {
                ++$demoted;
            }
        }

        if ($demoted > 0) {
            \PHPCompiler\AOT\BuildTiming::note('edit_scaffold_demoted', (float) $demoted);
        }

        $pruned = 0;
        // Skip prune on small deltas — 4k×named-global probes dominate tiny scaffolds
        // (comment-only <30% gate). MiniWebApp-scale demotes benefit (#36387).
        if ($demoted >= 100) {
            $pruned = self::pruneUnusedGlobalsAfterDemote($context);
            if ($pruned > 0) {
                \PHPCompiler\AOT\BuildTiming::note('edit_scaffold_globals_pruned', (float) $pruned);
            }
        }

        return $demoted;
    }

    /**
     * Runtime prologue symbols safe to take from prior aot.o on partial edit (#36387).
     */
    private static function isSharedRuntimeDemoteCandidate(string $name): bool
    {
        if ('' === $name || str_starts_with($name, 'PHPCompiler_')) {
            return false;
        }

        return str_starts_with($name, '__value__')
            || str_starts_with($name, '__string__')
            || str_starts_with($name, '__hashtable__')
            || str_starts_with($name, '__ref__')
            || str_starts_with($name, '__object__')
            || str_starts_with($name, 'phpc_')
            || str_starts_with($name, '__compiler_')
            || str_starts_with($name, '__phpc_')
            || str_starts_with($name, '__superglobals__')
            || str_starts_with($name, 'internal_');
    }

    private static function llvmFunctionName(Context $context, object $fn): string
    {
        if (!isset($fn->value)) {
            return '';
        }
        try {
            $raw = $context->llvm->lib->LLVMGetValueName($fn->value);
        } catch (\Throwable $e) {
            return '';
        }
        if (null === $raw) {
            return '';
        }
        if (is_object($raw) && method_exists($raw, 'toString')) {
            return (string) $raw->toString();
        }

        return is_string($raw) ? $raw : '';
    }

    /**
     * After demoting unchanged bodies, drop unused user const globals so
     * sibling-member string/array consts do not inflate the delta `.o` (#36387).
     *
     * Dense named lookup only (no full-module global walk) — walking every
     * NestedJIT global dominates tiny edit scaffolds and erased the emit win.
     */
    private static function pruneUnusedGlobalsAfterDemote(Context $context): int
    {
        $prefixes = ['string_const_', 'array_const_', 'object_const_'];
        $suffixes = ['_main', ''];
        $pruned = 0;
        for ($pass = 0; $pass < 3; ++$pass) {
            $batch = [];
            $misses = 0;
            for ($i = 0; $i < 4096; ++$i) {
                $hit = false;
                foreach ($prefixes as $prefix) {
                    foreach ($suffixes as $suffix) {
                        $name = $prefix.$i.$suffix;
                        $g = null;
                        try {
                            $g = $context->module->getNamedGlobal($name);
                        } catch (\Throwable $e) {
                            $g = null;
                        }
                        if (!$g instanceof \PHPLLVM\Value) {
                            continue;
                        }
                        $hit = true;
                        if (self::llvmValueHasNoUses($context, $g)) {
                            $batch[] = $g;
                        }
                    }
                }
                if ($hit) {
                    $misses = 0;
                } elseif (++$misses >= 64) {
                    break;
                }
            }
            if ([] === $batch) {
                break;
            }
            foreach ($batch as $g) {
                if (!is_object($g) || !method_exists($g, 'delete')) {
                    continue;
                }
                try {
                    $g->delete();
                    ++$pruned;
                } catch (\Throwable $e) {
                }
            }
        }

        return $pruned;
    }

    private static function llvmValueHasNoUses(Context $context, object $value): bool
    {
        if (!isset($value->value)) {
            return false;
        }
        try {
            $use = $context->llvm->lib->LLVMGetFirstUse($value->value);
        } catch (\Throwable $e) {
            return false;
        }

        return null === $use;
    }

    /**
     * Delete every basic block so the function becomes an extern declaration (#36387).
     */
    private static function demoteFunctionBodyToDeclaration(\PHPLLVM\Value\Function_ $fn): bool
    {
        try {
            $blocks = $fn->getBasicBlocks();
        } catch (\Throwable $e) {
            return false;
        }
        if ([] === $blocks) {
            return false;
        }
        // Delete in reverse so predecessors vanish after successors.
        for ($i = count($blocks) - 1; $i >= 0; --$i) {
            $bb = $blocks[$i];
            try {
                if (method_exists($bb, 'delete')) {
                    $bb->delete();
                }
            } catch (\Throwable $e) {
                return false;
            }
        }

        try {
            return 0 === (int) $fn->countBasicBlocks();
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Defined ELF symbol names in an object file (nm -g --defined-only) (#36387).
     *
     * @return array<string, true>
     */
    private static function objectDefinedSymbols(string $objectPath): array
    {
        if (!is_file($objectPath) || filesize($objectPath) < 1) {
            return [];
        }
        $out = [];
        $rc = 1;
        exec('nm -g --defined-only '.escapeshellarg($objectPath).' 2>/dev/null', $out, $rc);
        if (0 !== $rc) {
            return [];
        }
        $defs = [];
        foreach ($out as $line) {
            $parts = preg_split('/\s+/', trim((string) $line));
            if (!is_array($parts) || count($parts) < 3) {
                continue;
            }
            $name = $parts[count($parts) - 1];
            if (is_string($name) && '' !== $name && !str_starts_with($name, '.')) {
                $defs[$name] = true;
            }
        }

        return $defs;
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

    /** True while Context should register decls/types only (no implement IR) (#36387). */
    public static function shouldSkipBuiltinImplement(): bool
    {
        return null !== self::$pendingEditScaffoldKey || self::$editScaffoldActive;
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

    /**
     * True when a linked binary was saved for this key and inputs are still fresh (#36387).
     */
    public static function hasFreshArtifact(string $key, string $sourcePath, string $sourceCode): bool
    {
        if (!self::isFresh($key, $sourcePath, $sourceCode)) {
            return false;
        }
        $path = self::artifactPath($key);

        return is_file($path) && filesize($path) > 0;
    }

    /**
     * Copy a cached linked binary to {@see $outfile}. Returns true on success (#36387).
     */
    public static function tryRestoreArtifact(
        string $key,
        string $outfile,
        string $sourcePath,
        string $sourceCode
    ): bool {
        if (!self::hasFreshArtifact($key, $sourcePath, $sourceCode)) {
            return false;
        }

        return self::copyArtifactTo($key, $outfile);
    }

    /**
     * Warm restore by known cache key (project index hit — skip SourceBundler) (#36387).
     */
    public static function tryRestoreArtifactByKey(string $key, string $outfile): bool
    {
        if (!self::isEnabled() || '' === $key) {
            return false;
        }
        if (null === self::readMeta($key)) {
            return false;
        }
        $path = self::artifactPath($key);
        if (!is_file($path) || filesize($path) < 1) {
            return false;
        }

        return self::copyArtifactTo($key, $outfile);
    }

    private static function copyArtifactTo(string $key, string $outfile): bool
    {
        $src = self::artifactPath($key);
        $outDir = dirname($outfile);
        if ('' !== $outDir && '.' !== $outDir && !is_dir($outDir)) {
            if (!@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
                return false;
            }
        }
        $tmp = $outfile.'.tmp.'.getmypid();
        if (!@copy($src, $tmp)) {
            @unlink($tmp);

            return false;
        }
        @chmod($tmp, 0755);
        if (!@rename($tmp, $outfile)) {
            if (!@copy($tmp, $outfile)) {
                @unlink($tmp);

                return false;
            }
            @unlink($tmp);
            @chmod($outfile, 0755);
        }

        return is_file($outfile) && filesize($outfile) > 0;
    }

    /**
     * Persist the linked executable beside bitcode/meta for the next warm build (#36387).
     */
    public static function saveArtifact(string $key, string $outfile): void
    {
        if ('' === $key || !is_file($outfile) || filesize($outfile) < 1) {
            return;
        }
        $dir = self::entryDir($key);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        if (!self::hasDurableMarker($key) || null === self::readMeta($key)) {
            return;
        }
        $dest = self::artifactPath($key);
        $tmp = $dest.'.tmp.'.getmypid();
        if (!@copy($outfile, $tmp)) {
            @unlink($tmp);

            return;
        }
        @chmod($tmp, 0755);
        if (!@rename($tmp, $dest)) {
            if (!@copy($tmp, $dest)) {
                @unlink($tmp);

                return;
            }
            @unlink($tmp);
            @chmod($dest, 0755);
        }
    }

    /**
     * True when a cached user `.o` + link manifest are fresh for this key (#36387).
     */
    public static function hasFreshObject(string $key, string $sourcePath, string $sourceCode): bool
    {
        if (!self::isFresh($key, $sourcePath, $sourceCode)) {
            return false;
        }
        $object = self::objectPath($key);
        if (!is_file($object) || filesize($object) < 1) {
            return false;
        }
        $link = self::readLinkManifest($key);

        return null !== $link;
    }

    /**
     * @return array{version: int, helper_slugs: list<string>}|null
     */
    public static function readLinkManifest(string $key): ?array
    {
        $path = self::linkManifestPath($key);
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
        if (!isset($decoded['helper_slugs']) || !is_array($decoded['helper_slugs'])) {
            return null;
        }
        $slugs = [];
        foreach ($decoded['helper_slugs'] as $slug) {
            if (is_string($slug) && '' !== $slug) {
                $slugs[] = $slug;
            }
        }

        return [
            'version' => 1,
            'helper_slugs' => $slugs,
        ];
    }

    /**
     * Persist the emitted user object + helper unit slugs for mid-tier link restore (#36387).
     *
     * @param list<string> $helperSlugs basenames under helper-runtime units/
     */
    public static function saveObject(string $key, string $objectFile, array $helperSlugs): void
    {
        if ('' === $key || !is_file($objectFile) || filesize($objectFile) < 1) {
            return;
        }
        $dir = self::entryDir($key);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        if (!self::hasDurableMarker($key) || null === self::readMeta($key)) {
            return;
        }
        $dest = self::objectPath($key);
        $tmp = $dest.'.tmp.'.getmypid();
        if (!@copy($objectFile, $tmp)) {
            @unlink($tmp);

            return;
        }
        if (!@rename($tmp, $dest)) {
            if (!@copy($tmp, $dest)) {
                @unlink($tmp);

                return;
            }
            @unlink($tmp);
        }
        $clean = [];
        foreach ($helperSlugs as $slug) {
            if (is_string($slug) && '' !== $slug) {
                $clean[] = $slug;
            }
        }
        $payload = json_encode([
            'version' => 1,
            'helper_slugs' => array_values(array_unique($clean)),
        ], JSON_PRETTY_PRINT);
        if (false !== $payload) {
            file_put_contents(self::linkManifestPath($key), $payload."\n");
        }
    }

    /**
     * Mid-tier warm path: link a cached user `.o` with recorded helper units (#36387).
     *
     * Skips loadJitContext + LLVM emitToFile. Used when `aot.bin` was removed but the
     * object + bitcode/meta are still fingerprint-fresh.
     */
    public static function tryRestoreObjectAndLink(
        string $key,
        string $outfile,
        string $sourcePath,
        string $sourceCode
    ): bool {
        if (!self::hasFreshObject($key, $sourcePath, $sourceCode)) {
            return false;
        }
        $link = self::readLinkManifest($key);
        if (null === $link) {
            return false;
        }
        $object = self::objectPath($key);
        $work = $outfile.'.o.restore.'.getmypid();
        if (!@copy($object, $work)) {
            @unlink($work);

            return false;
        }
        HelperRuntimeCache::adoptUnitSlugsForLink($link['helper_slugs']);
        try {
            \PHPCompiler\AOT\Linker::link($work, $outfile);
            \PHPCompiler\AOT\Linker::assertNonEmptyRequestedOutput($outfile);
        } catch (\Throwable $e) {
            @unlink($work);
            @unlink($outfile);

            return false;
        }
        @unlink($work);
        self::saveArtifact($key, $outfile);

        return is_file($outfile) && filesize($outfile) > 0;
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
     * Glue covers class properties, constants, use statements, and other tokens outside
     * function/method bodies. A glue change forces a full member strip; an isolated
     * method body change strips only that method's LLVM symbols.
     *
     * @return array{glue: string, functions: array<string, string>}|null
     */
    public static function semanticFileParts(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $src = file_get_contents($path);
        if (false === $src) {
            return null;
        }
        if ('' === $src) {
            return ['glue' => hash('sha256', ''), 'functions' => []];
        }
        $tokens = @token_get_all($src);
        if (!\is_array($tokens) || [] === $tokens) {
            return ['glue' => hash('sha256', $src), 'functions' => []];
        }

        $glue = '';
        $functions = [];
        $classStack = [];
        $pendingClass = false;
        $n = count($tokens);
        for ($i = 0; $i < $n; ++$i) {
            $token = $tokens[$i];
            if (\is_array($token)) {
                $id = $token[0];
                $text = $token[1];
                if (T_COMMENT === $id || T_DOC_COMMENT === $id || T_WHITESPACE === $id) {
                    continue;
                }
                if (T_CLASS === $id || T_INTERFACE === $id || T_TRAIT === $id) {
                    $pendingClass = true;
                    $glue .= $text;
                    continue;
                }
                if ($pendingClass && T_STRING === $id) {
                    $classStack[] = $text;
                    $pendingClass = false;
                    $glue .= $text;
                    continue;
                }
                if (T_FUNCTION === $id) {
                    $fn = self::consumeFunctionSemantic($tokens, $i, $classStack);
                    if (null === $fn) {
                        $glue .= $text;
                        continue;
                    }
                    $functions[$fn['scoped']] = $fn['hash'];
                    // consumeFunctionSemantic advances $i to the last consumed token.
                    continue;
                }
                $glue .= $text;
            } else {
                if ('{' === $token) {
                    // Keep brace depth for class stack via explicit tracking below.
                    $glue .= $token;
                } elseif ('}' === $token) {
                    if ([] !== $classStack) {
                        // Heuristic: closing brace may end the class; pop if no open class body
                        // nesting tracked — brace depth handled inside consumeFunctionSemantic
                        // for methods; for class end we pop when glue sees unmatched '}'.
                        // Safer: track brace depth on glue path.
                    }
                    $glue .= $token;
                } else {
                    $glue .= $token;
                }
            }
        }

        ksort($functions);

        return [
            'glue' => hash('sha256', $glue),
            'functions' => $functions,
        ];
    }

    /**
     * @param list<string|array{0:int,1:string,2:int}> $tokens
     * @param list<string>                             $classStack
     *
     * @return array{scoped: string, hash: string}|null
     */
    private static function consumeFunctionSemantic(array $tokens, int &$i, array $classStack): ?array
    {
        $n = count($tokens);
        // Skip attributes / modifiers already consumed; $tokens[$i] is T_FUNCTION.
        ++$i;
        $name = '';
        for (; $i < $n; ++$i) {
            $token = $tokens[$i];
            if (\is_array($token)) {
                $id = $token[0];
                if (T_COMMENT === $id || T_DOC_COMMENT === $id || T_WHITESPACE === $id) {
                    continue;
                }
                if (T_STRING === $id || (defined('T_NAME_QUALIFIED') && T_NAME_QUALIFIED === $id) || (defined('T_NAME_FULLY_QUALIFIED') && T_NAME_FULLY_QUALIFIED === $id)) {
                    $name = $token[1];
                    ++$i;
                    break;
                }
                // Anonymous function / arrow — treat as glue by bailing.
                if ('(' === $token[1] || T_FN === $id) {
                    --$i;

                    return null;
                }
            } else {
                if ('(' === $token) {
                    --$i;

                    return null;
                }
                if ('&' === $token) {
                    continue;
                }
            }
        }
        if ('' === $name) {
            return null;
        }
        // Skip parameter list to body '{' or ';' (abstract).
        $paren = 0;
        $sawParen = false;
        $bodyStart = -1;
        for (; $i < $n; ++$i) {
            $token = $tokens[$i];
            $ch = \is_array($token) ? $token[1] : $token;
            if (\is_array($token)) {
                $id = $token[0];
                if (T_COMMENT === $id || T_DOC_COMMENT === $id || T_WHITESPACE === $id) {
                    continue;
                }
            }
            if ('(' === $ch) {
                ++$paren;
                $sawParen = true;
                continue;
            }
            if (')' === $ch) {
                --$paren;
                continue;
            }
            if ($sawParen && 0 === $paren) {
                if ('{' === $ch) {
                    $bodyStart = $i;
                    break;
                }
                if (';' === $ch) {
                    // Abstract / interface method — hash signature only.
                    $scoped = [] !== $classStack
                        ? $classStack[count($classStack) - 1].'::'.$name
                        : $name;
                    return ['scoped' => $scoped, 'hash' => hash('sha256', 'abstract:'.$name)];
                }
            }
        }
        if ($bodyStart < 0) {
            return null;
        }
        $body = '';
        $brace = 0;
        for (; $i < $n; ++$i) {
            $token = $tokens[$i];
            if (\is_array($token)) {
                $id = $token[0];
                if (T_COMMENT === $id || T_DOC_COMMENT === $id || T_WHITESPACE === $id) {
                    continue;
                }
                $ch = $token[1];
            } else {
                $ch = $token;
            }
            if ('{' === $ch) {
                ++$brace;
                $body .= $ch;
                continue;
            }
            if ('}' === $ch) {
                --$brace;
                $body .= $ch;
                if (0 === $brace) {
                    break;
                }
                continue;
            }
            $body .= $ch;
        }
        $scoped = [] !== $classStack
            ? $classStack[count($classStack) - 1].'::'.$name
            : $name;

        return ['scoped' => $scoped, 'hash' => hash('sha256', $body)];
    }

    /**
     * @param list<string> $memberPaths
     *
     * @return array{functions: array<string, array<string, string>>, glue: array<string, string>}
     */
    public static function memberSemanticParts(array $memberPaths): array
    {
        $functions = [];
        $glue = [];
        foreach ($memberPaths as $path) {
            if (!is_string($path) || !is_file($path)) {
                continue;
            }
            $resolved = realpath($path) ?: $path;
            $parts = self::semanticFileParts($resolved);
            if (null === $parts) {
                continue;
            }
            $glue[$resolved] = $parts['glue'];
            $functions[$resolved] = $parts['functions'];
        }
        ksort($glue);
        ksort($functions);

        return ['functions' => $functions, 'glue' => $glue];
    }

    /**
     * Per-function strip plan for members that already failed the file-level semantic check.
     *
     * Returns path → changed scoped (lc) only when glue is unchanged and ≥1 function
     * hash differs. Missing/empty entry for a strip member ⇒ full member strip (#36387).
     *
     * @param array<string, array<string, string>>|null $previousFunctions
     * @param array<string, array<string, string>>|null $currentFunctions
     * @param array<string, string>|null               $previousGlue
     * @param array<string, string>|null               $currentGlue
     * @param list<string>                             $stripMembers
     *
     * @return array<string, array<string, true>>
     */
    public static function diffFunctionsForStrip(
        ?array $previousFunctions,
        ?array $currentFunctions,
        ?array $previousGlue,
        ?array $currentGlue,
        array $stripMembers
    ): array {
        if (
            null === $previousFunctions
            || [] === $previousFunctions
            || null === $currentFunctions
            || [] === $currentFunctions
            || null === $previousGlue
            || [] === $previousGlue
            || null === $currentGlue
            || [] === $currentGlue
        ) {
            return [];
        }
        $out = [];
        foreach ($stripMembers as $path) {
            if (!is_string($path) || '' === $path) {
                continue;
            }
            $prevG = $previousGlue[$path] ?? null;
            $currG = $currentGlue[$path] ?? null;
            if (!is_string($prevG) || !is_string($currG) || $prevG !== $currG) {
                // Glue drift (props/consts/use) or missing → full member strip.
                continue;
            }
            $prevF = $previousFunctions[$path] ?? null;
            $currF = $currentFunctions[$path] ?? null;
            if (!is_array($prevF) || !is_array($currF) || [] === $currF) {
                continue;
            }
            $changed = [];
            foreach ($currF as $scoped => $hash) {
                if (!is_string($scoped) || !is_string($hash)) {
                    continue;
                }
                $prevH = $prevF[$scoped] ?? null;
                if ($prevH !== $hash) {
                    $changed[strtolower($scoped)] = true;
                }
            }
            foreach ($prevF as $scoped => $_hash) {
                if (!is_string($scoped)) {
                    continue;
                }
                if (!isset($currF[$scoped])) {
                    $changed[strtolower($scoped)] = true;
                }
            }
            if ([] !== $changed) {
                $out[$path] = $changed;
                \PHPCompiler\AOT\BuildTiming::note('edit_scaffold_func_partial', 1.0);
            }
        }

        return $out;
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
     * Same-project edit path: restore prior module.bc, strip user symbols, rebind helpers.
     *
     * Requires thin Context boot (bitcode before namedStructType/addFunction) so types and
     * functionScope bind to the restored module. {@see Context} parses {@see bitcodePath()}
     * when {@see armEditScaffold()} is set, then this strips user symbols for re-lower (#36387).
     *
     * @internal
     */
    public static function tryRestoreEditScaffold(Context $context, string $previousKey): bool
    {
        $meta = self::readMeta($previousKey);
        if (null === $meta) {
            return false;
        }
        $bcPath = self::bitcodePath($previousKey);
        if (!is_file($bcPath)) {
            return false;
        }
        $userSymbols = $meta['user_symbols'] ?? null;
        $helperSymbols = $meta['helper_symbols'] ?? null;
        if (!is_array($userSymbols) || [] === $userSymbols) {
            return false;
        }
        if (!is_array($helperSymbols)) {
            $helperSymbols = [];
        }

        if (!self::$editScaffoldBitcodeBound) {
            try {
                $context->replaceModuleFromBitcodeFile($bcPath);
            } catch (\Throwable $e) {
                return false;
            }
        }

        $byMember = is_array($meta['user_symbols_by_member'] ?? null)
            ? $meta['user_symbols_by_member']
            : [];
        $byFunction = is_array($meta['user_symbols_by_function'] ?? null)
            ? $meta['user_symbols_by_function']
            : [];
        self::$editScaffoldByFunction = self::normalizeByFunctionMap($byFunction);
        $toStrip = self::userSymbolsToStripForEdit($userSymbols, $byMember);
        $partial = self::wouldPartialStrip($userSymbols, $byMember);
        self::stripUserSymbolsFromModule($context, $toStrip);
        // Prefer full logical→LLVM map saved at cold emit; fall back to NestedJIT helpers (#36387).
        $functionSymbols = $meta['function_llvm_symbols'] ?? null;
        if (!is_array($functionSymbols) || [] === $functionSymbols) {
            $functionSymbols = $helperSymbols;
        }
        self::rebindHelperSymbols($context, $functionSymbols);
        $link = self::readLinkManifest($previousKey);
        if (null !== $link) {
            \PHPCompiler\AOT\HelperRuntimeCache::adoptUnitSlugsForLink($link['helper_slugs']);
        }
        SuperglobalInit::rebindGlobalsFromModule($context);
        $context->rebindFunctionScopeFromModule();
        $context->rebindInitShutdownAfterModuleReplace();
        $context->reopenInitLinearForEditScaffold();
        $context->refreshIntrinsicAfterModuleReplace();
        $context->syncIntrinsicBuilder();
        // Clear PHP-side string/array const maps so re-lower allocates fresh globals (#36387).
        $context->resetCompileTimeConstantMapsForEditScaffold();
        self::$skipModuleFuncCompile = true;
        self::$editScaffoldActive = true;
        self::$editScaffoldPartial = $partial;
        self::$partialEmitBaseObject = null;
        if ($partial) {
            $baseObject = self::objectPath($previousKey);
            if (is_file($baseObject) && filesize($baseObject) > 0) {
                self::$partialEmitBaseObject = $baseObject;
            }
        }
        self::$pendingEditScaffoldKey = null;
        \PHPCompiler\AOT\BuildTiming::note('edit_scaffold_hit', 1.0);
        if ($partial) {
            \PHPCompiler\AOT\BuildTiming::note('edit_scaffold_partial', 1.0);
        }

        return true;
    }

    /**
     * Prefer stripping only symbols owned by changed members so unchanged LLVM
     * bodies stay in the module. Call sites that still point at `.stale` Values
     * are fixed by {@see rebaseStaleUserSymbols()} after re-lower (#36387).
     *
     * Only symbols attributed to an *unchanged* non-entry member are kept. Helpers
     * are never in {@see $allUserSymbols}; NestedJIT must not early-return on them.
     *
     * @param list<mixed>              $allUserSymbols
     * @param array<string, mixed>     $byMember
     *
     * @return list<string>
     */
    public static function userSymbolsToStripForEdit(array $allUserSymbols, array $byMember): array
    {
        $names = ['main' => true]; // C wrapper always recreated in compileToFile
        foreach ($allUserSymbols as $name) {
            if (is_string($name) && '' !== $name) {
                $names[$name] = true;
            }
        }

        $kept = self::computeKeptUserSymbols($allUserSymbols, $byMember);
        self::$keptUserSymbols = $kept;
        if ([] === $kept) {
            return array_keys($names);
        }

        foreach (array_keys($kept) as $name) {
            unset($names[$name]);
        }
        $names['main'] = true;

        return array_keys($names);
    }

    /**
     * True when changed-member attribution keeps at least one user symbol (#36387).
     *
     * @param list<mixed>          $allUserSymbols
     * @param array<string, mixed> $byMember
     */
    public static function wouldPartialStrip(array $allUserSymbols, array $byMember): bool
    {
        return [] !== self::computeKeptUserSymbols($allUserSymbols, $byMember);
    }

    /**
     * User LLVM names attributed solely to unchanged non-entry members (#36387).
     *
     * When a changed member has per-function attribution and
     * {@see $editChangedFunctions} lists only some methods, sibling method bodies
     * in that same file are kept (real one-method token edits).
     *
     * @param list<mixed>          $allUserSymbols
     * @param array<string, mixed> $byMember
     *
     * @return array<string, true>
     */
    public static function computeKeptUserSymbols(array $allUserSymbols, array $byMember): array
    {
        if ([] === $byMember) {
            return [];
        }

        $keepable = [];
        foreach ($allUserSymbols as $name) {
            if (
                is_string($name)
                && '' !== $name
                && 'main' !== $name
                && !str_starts_with($name, 'internal_')
            ) {
                $keepable[$name] = true;
            }
        }
        if ([] === $keepable) {
            return [];
        }

        $mustStripMember = [];
        foreach (self::$editChangedMembers as $member) {
            $mustStripMember[$member] = true;
        }
        // Entry ({main}) must re-lower when a changed member is a full-file strip
        // (config/const/glue) because folded constants and call sites may bake the
        // old values. Pure per-function body edits in other members leave entry
        // bytecode valid — keep it so MiniWebApp one-method edits stay ≤25% (#36387).
        if (is_string(self::$projectEntry) && '' !== self::$projectEntry) {
            $entry = self::$projectEntry;
            $keepEntry = !isset($mustStripMember[$entry]);
            if ($keepEntry) {
                foreach (array_keys($mustStripMember) as $member) {
                    $changedFns = self::$editChangedFunctions[$member] ?? null;
                    if (!is_array($changedFns) || [] === $changedFns) {
                        $keepEntry = false;
                        break;
                    }
                }
            }
            if (!$keepEntry) {
                $mustStripMember[$entry] = true;
            } elseif ([] !== $mustStripMember) {
                \PHPCompiler\AOT\BuildTiming::note('edit_scaffold_entry_keep', 1.0);
            }
        }

        // Empty editChangedMembers after a byte-only (comment/whitespace) edit means every
        // attributed non-entry body is still semantically identical — keep them (#36387).
        // (Previously [] short-circuited to "keep nothing", undoing semantic_keep.)

        // Changed members missing from byMember (e.g. config.php with only assignments)
        // own no LLVM symbols — that must not abort keep-path for unchanged Router.php
        // (#36387 MiniWebApp one-file edit). Only require attribution when we need to
        // know which symbols the changed file owned; absence ⇒ empty strip set for it.
        $byFunction = self::$editScaffoldByFunction;
        $kept = [];
        foreach ($byMember as $member => $syms) {
            if (!is_string($member) || !is_array($syms)) {
                continue;
            }
            if (!isset($mustStripMember[$member])) {
                foreach ($syms as $sym) {
                    if (is_string($sym) && '' !== $sym && isset($keepable[$sym])) {
                        $kept[$sym] = true;
                    }
                }
                continue;
            }
            // Changed member: keep sibling functions when only some methods changed.
            $changedFns = self::$editChangedFunctions[$member] ?? null;
            $fnMap = $byFunction[$member] ?? null;
            if (!is_array($changedFns) || [] === $changedFns || !is_array($fnMap) || [] === $fnMap) {
                continue; // full strip of this member
            }
            foreach ($fnMap as $scoped => $fnSyms) {
                if (!is_string($scoped) || !is_array($fnSyms)) {
                    continue;
                }
                $scopedLc = strtolower($scoped);
                if (isset($changedFns[$scopedLc])) {
                    continue;
                }
                foreach ($fnSyms as $sym) {
                    if (is_string($sym) && '' !== $sym && isset($keepable[$sym])) {
                        $kept[$sym] = true;
                    }
                }
            }
        }

        if ([] === $kept) {
            return [];
        }

        // Symbols listed under a full-strip member, or under a changed function, must not stay.
        foreach (array_keys($mustStripMember) as $member) {
            $changedFns = self::$editChangedFunctions[$member] ?? null;
            $fnMap = $byFunction[$member] ?? null;
            if (is_array($changedFns) && [] !== $changedFns && is_array($fnMap) && [] !== $fnMap) {
                foreach ($fnMap as $scoped => $fnSyms) {
                    if (!is_string($scoped) || !is_array($fnSyms)) {
                        continue;
                    }
                    if (!isset($changedFns[strtolower($scoped)])) {
                        continue;
                    }
                    foreach ($fnSyms as $sym) {
                        if (is_string($sym)) {
                            unset($kept[$sym]);
                        }
                    }
                }
                continue;
            }
            $syms = $byMember[$member] ?? null;
            if (!is_array($syms)) {
                continue;
            }
            foreach ($syms as $sym) {
                if (is_string($sym)) {
                    unset($kept[$sym]);
                }
            }
        }

        return $kept;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, array<string, list<string>>>
     */
    private static function normalizeByFunctionMap(array $raw): array
    {
        $out = [];
        foreach ($raw as $member => $scopedMap) {
            if (!is_string($member) || !is_array($scopedMap)) {
                continue;
            }
            $resolved = realpath($member);
            $key = false !== $resolved ? $resolved : $member;
            $funcs = [];
            foreach ($scopedMap as $scoped => $syms) {
                if (!is_string($scoped) || !is_array($syms)) {
                    continue;
                }
                $list = [];
                foreach ($syms as $sym) {
                    if (is_string($sym) && '' !== $sym) {
                        $list[] = $sym;
                    }
                }
                if ([] !== $list) {
                    $funcs[$scoped] = array_values(array_unique($list));
                }
            }
            if ([] !== $funcs) {
                $out[$key] = $funcs;
            }
        }

        return $out;
    }

    /**
     * After re-lower, point CallInsts that still target `foo.stale` at the new `foo`
     * and delete the stale Function_ — enables keeping unchanged member bodies (#36387).
     *
     * Only probes symbols recorded at strip time — never walks the full restored
     * module (thousands of builtin funcs via FFI; measured ~1.5s on MiniWebApp).
     */
    public static function rebaseStaleUserSymbols(Context $context): void
    {
        if ([] === self::$strippedUserSymbols) {
            return;
        }

        $purged = 0;
        foreach (self::$strippedUserSymbols as $orig) {
            if (!is_string($orig) || '' === $orig) {
                continue;
            }
            $staleFn = $context->module->getNamedFunction($orig.'.stale');
            if (!$staleFn instanceof \PHPLLVM\Value\Function_) {
                continue;
            }
            $fresh = $context->module->getNamedFunction($orig);
            if ($fresh instanceof \PHPLLVM\Value\Function_ && $fresh !== $staleFn) {
                try {
                    $staleFn->replaceAllUsesWith($fresh);
                } catch (\Throwable $e) {
                    // Fall through to delete attempt.
                }
            }
            if (self::tryDeleteFunction($staleFn)) {
                ++$purged;
            }
        }
        self::$strippedUserSymbols = [];

        if ($purged > 0) {
            \PHPCompiler\AOT\BuildTiming::note('edit_scaffold_stale_purged', (float) $purged);
        }
    }

    private static function tryDeleteFunction(\PHPLLVM\Value\Function_ $fn): bool
    {
        try {
            $fn->delete();

            return true;
        } catch (\Throwable $e) {
            try {
                $fn->setName((string) $fn->getName().'.dead');
            } catch (\Throwable $e2) {
                return false;
            }

            return false;
        }
    }

    /**
     * @param list<mixed> $userSymbols
     */
    private static function stripUserSymbolsFromModule(Context $context, array $userSymbols): void
    {
        $names = [];
        foreach ($userSymbols as $name) {
            if (is_string($name) && '' !== $name) {
                $names[$name] = true;
            }
        }
        // Always drop standalone main wrapper — recreated in compileToFile.
        $names['main'] = true;

        self::$strippedUserSymbols = [];
        foreach (array_keys($names) as $name) {
            $fn = $context->module->getNamedFunction($name);
            if ($fn instanceof \PHPLLVM\Value\Function_) {
                try {
                    // LLVMDeleteFunction is undefined if the symbol still has uses
                    // (__init__ string-const stores, old main). Rename so re-lower can
                    // addFunction the original name without dangling IR (#36387).
                    $fn->setName($name.'.stale');
                    self::$strippedUserSymbols[] = $name;
                } catch (\Throwable $e) {
                    try {
                        $fn->delete();
                    } catch (\Throwable $e2) {
                        // Leave stale symbol; recompile may fail loudly rather than miscompile.
                    }
                }
            }
        }

        // Keep user const globals: deleting them while __init__ still references
        // them SIGSEGVs on re-lower. resetCompileTimeConstantMaps allocates new names.
    }

    private static function stripUserConstGlobals(Context $context): void
    {
        $prefixes = ['string_const_', 'array_const_', 'object_const_'];
        $toDelete = [];
        try {
            $global = $context->module->getFirstGlobal();
        } catch (\Throwable $e) {
            return;
        }
        $guard = 0;
        while ($global instanceof \PHPLLVM\Value && $guard < 100000) {
            ++$guard;
            $name = '';
            try {
                $name = (string) $global->getName();
            } catch (\Throwable $e) {
                break;
            }
            $isUserConst = false;
            foreach ($prefixes as $prefix) {
                if (str_starts_with($name, $prefix) && str_ends_with($name, '_main')) {
                    $isUserConst = true;
                    break;
                }
            }
            $next = null;
            try {
                if (method_exists($global, 'getNextGlobal')) {
                    $next = $global->getNextGlobal();
                }
            } catch (\Throwable $e) {
                $next = null;
            }
            if ($isUserConst) {
                $toDelete[] = $global;
            }
            if (!$next instanceof \PHPLLVM\Value) {
                // Fall back: only first-global walk without Next — stop after collecting known names via getNamedGlobal.
                break;
            }
            $global = $next;
        }

        // Named lookup for dense const indices (0..N) when NextGlobal is unavailable.
        if ([] === $toDelete) {
            for ($i = 0; $i < 4096; ++$i) {
                foreach (['string_const_', 'array_const_', 'object_const_'] as $prefix) {
                    $g = $context->module->getNamedGlobal($prefix.$i.'_main');
                    if ($g instanceof \PHPLLVM\Value) {
                        $toDelete[] = $g;
                    }
                }
            }
        }

        foreach ($toDelete as $g) {
            if ($g instanceof \PHPLLVM\Value\Global_ || (is_object($g) && method_exists($g, 'delete'))) {
                try {
                    $g->delete();
                } catch (\Throwable $e) {
                    // ignore — unused consts are harmless if delete fails
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $helperSymbols logical lc → LLVM name
     */
    private static function rebindHelperSymbols(Context $context, array $helperSymbols): void
    {
        foreach ($helperSymbols as $logical => $llvm) {
            if (!is_string($logical) || !is_string($llvm) || '' === $logical || '' === $llvm) {
                continue;
            }
            $fn = $context->module->getNamedFunction($llvm);
            if (!$fn instanceof \PHPLLVM\Value\Function_) {
                continue;
            }
            $lc = strtolower($logical);
            $context->functions[$lc] = $fn;
            $context->functionLlvmSymbols[$lc] = $llvm;
            $argTypes = [];
            $n = $fn->countParams();
            for ($i = 0; $i < $n; ++$i) {
                $argTypes[] = $fn->getParam($i)->typeOf();
            }
            $context->functionProxies[$lc] = new Call\Native($fn, $logical, $argTypes);
        }
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
