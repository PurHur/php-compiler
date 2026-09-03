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

    private static ?string $recordingKey = null;

    private static bool $skipModuleFuncCompile = false;

    /** True after {@see tryRestoreEditScaffold()} — helpers kept, user symbols stripped. */
    private static bool $editScaffoldActive = false;

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
    public static function recordUserLlvmSymbol(string $llvmName): void
    {
        if (null === self::$recordingUserSymbols || '' === $llvmName) {
            return;
        }
        self::$recordingUserSymbols[] = $llvmName;
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
        $context->rebindInitShutdownAfterModuleReplace();
        $context->syncIntrinsicBuilder();
        self::$skipModuleFuncCompile = true;
        self::$editScaffoldActive = false;

        return true;
    }

    /**
     * Same-project edit path (deferred): restore prior module.bc, strip user symbols, rebind helpers.
     *
     * Not wired into {@see \PHPCompiler\Runtime::standalone} yet — replacing the module after
     * {@see Context} construct leaves defineBuiltins Values dangling (SIGSEGV on re-lower).
     * Next slice needs thin Context init (skip implement, bind from bitcode) before this can land.
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

        try {
            $context->replaceModuleFromBitcodeFile($bcPath);
        } catch (\Throwable $e) {
            return false;
        }

        self::stripUserSymbolsFromModule($context, $userSymbols);
        self::rebindHelperSymbols($context, $helperSymbols);
        SuperglobalInit::rebindGlobalsFromModule($context);
        $context->rebindInitShutdownAfterModuleReplace();
        $context->reopenInitLinearForEditScaffold();
        $context->syncIntrinsicBuilder();
        // Clear PHP-side string/array const maps so re-lower allocates fresh globals (#36387).
        $context->resetCompileTimeConstantMapsForEditScaffold();
        self::$skipModuleFuncCompile = true;
        self::$editScaffoldActive = true;
        \PHPCompiler\AOT\BuildTiming::note('edit_scaffold_hit', 1.0);

        return true;
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

        foreach (array_keys($names) as $name) {
            $fn = $context->module->getNamedFunction($name);
            if ($fn instanceof \PHPLLVM\Value\Function_) {
                try {
                    $fn->delete();
                } catch (\Throwable $e) {
                    // Leave stale symbol; recompile may fail loudly rather than miscompile.
                }
            }
        }

        // Drop user string/array const globals so re-lower can reuse _main suffixes (#36387).
        self::stripUserConstGlobals($context);
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
            'updated_at' => gmdate('c'),
        ], JSON_PRETTY_PRINT);
        if (false === $payload) {
            return;
        }
        file_put_contents(self::projectIndexPath($projectId), $payload."\n");
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
            $payload = json_encode([
                'version' => self::META_VERSION,
                'fingerprint' => self::fingerprint(),
                'exports' => self::$recordingExports,
                'user_symbols' => $userSymbols,
                'helper_symbols' => $helperSymbols,
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
        self::$skipModuleFuncCompile = false;
        self::$editScaffoldActive = false;
        self::$projectMembers = null;
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
