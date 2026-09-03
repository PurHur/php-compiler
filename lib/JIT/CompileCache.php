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
 * AOT warm rebuilds use {@see artifactPath()} / {@see objectPath()} — full-module
 * `module.bc` from user-script AOT does not round-trip (`LLVMParseBitcode` → Invalid
 * type even after compileCommon; same-process rewrite fails). AOT therefore writes
 * {@see stampPath()} + meta instead of relying on parseable bitcode (#36387).
 */
final class CompileCache
{
    private const META_VERSION = 1;

    /** @var list<array{llvm: string, signature: string, scoped: string}>|null */
    private static ?array $recordingExports = null;

    private static ?string $recordingKey = null;

    private static bool $skipModuleFuncCompile = false;

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

        // JIT: module.bc. AOT: fresh.stamp (full-module bitcode does not parse back).
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

        return true;
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
     * AOT cache entry without full-module bitcode (#36387).
     *
     * User-script AOT modules fail `LLVMParseBitcode` with Invalid type even when
     * written after compileCommon (same LLVMContext rewrite also fails). Persist
     * meta + {@see stampPath()} so artifact/object warm paths stay honest.
     */
    public static function saveAotStamp(string $key): void
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
            $payload = json_encode([
                'version' => self::META_VERSION,
                'fingerprint' => self::fingerprint(),
                'exports' => self::$recordingExports,
                'aot_stamp' => true,
            ], JSON_PRETTY_PRINT);
            if (false !== $payload) {
                file_put_contents(self::metaPath($key), $payload);
            }
            file_put_contents(self::stampPath($key), "aot\n");
            // Drop any prior unreadable full-module bitcode so isFresh does not
            // imply tryRestore can succeed.
            $bc = self::bitcodePath($key);
            if (is_file($bc)) {
                @unlink($bc);
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
        self::$skipModuleFuncCompile = false;
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
