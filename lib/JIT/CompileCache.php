<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;

/**
 * On-disk MCJIT bitcode cache (issue #153).
 *
 * Persists verified LLVM bitcode keyed by source bytes + compiler fingerprint so a
 * second `bin/jit.php` process can skip LLVM IR lowering when inputs are unchanged.
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
        $flag = getenv('PHP_COMPILER_CACHE');
        if (false !== $flag && ('0' === $flag || 'false' === strtolower($flag))) {
            return false;
        }
        if (getenv('PHP_COMPILER_SELFHOST_AOT') === '1') {
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
        $override = getenv('PHP_COMPILER_CACHE_DIR');
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
        if (!is_file(self::bitcodePath($key))) {
            return false;
        }

        return null !== self::readMeta($key);
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

        self::restoreExports($context, $block, $meta['exports']);
        self::$skipModuleFuncCompile = true;

        return true;
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
        $repoRoot = dirname(__DIR__, 2);
        $llvmDir = getenv('PHP_COMPILER_LLVM_PATH');
        if (false === $llvmDir || '' === $llvmDir) {
            $candidate = $repoRoot.'/.llvm';
            if (is_file($candidate.'/libLLVM-9.so.1')) {
                $llvmDir = $candidate;
            } elseif (is_file('/opt/llvm9/libLLVM-9.so.1')) {
                $llvmDir = '/opt/llvm9';
            } else {
                $llvmDir = 'no-llvm';
            }
        }
        $parts[] = $llvmDir;
        $parts[] = hash_file('sha256', __DIR__.'/../JIT/Context.php') ?: '';
        $parts[] = hash_file('sha256', __DIR__.'/../Runtime.php') ?: '';

        $cached = hash('sha256', implode("\0", $parts));

        return $cached;
    }
}
