<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * Resolve LLVM 9 for JIT/AOT tests (mirrors script/php-env.sh).
 */
final class LlvmToolchain
{
    private static ?bool $ready = null;

    /**
     * @return non-empty-string|null
     */
    public static function resolveDir(?string $repoRoot = null): ?string
    {
        $candidates = [];
        if (null !== $repoRoot) {
            $candidates[] = $repoRoot.'/.llvm';
        }
        $fromEnv = getenv('PHP_COMPILER_LLVM_PATH');
        if (false !== $fromEnv && '' !== $fromEnv) {
            $candidates[] = $fromEnv;
        }
        $candidates[] = '/opt/llvm9';

        foreach ($candidates as $dir) {
            if (is_file($dir.'/libLLVM-9.so.1')) {
                $resolved = realpath($dir);

                return false !== $resolved ? $resolved : $dir;
            }
        }

        return null;
    }

    public static function isReady(?string $repoRoot = null): bool
    {
        if (null !== self::$ready) {
            return self::$ready;
        }
        $dir = self::resolveDir($repoRoot);
        if (null === $dir) {
            self::$ready = false;

            return false;
        }
        if ('' === getenv('PHP_COMPILER_LLVM_PATH')) {
            putenv('PHP_COMPILER_LLVM_PATH='.$dir);
            $_ENV['PHP_COMPILER_LLVM_PATH'] = $dir;
            $_SERVER['PHP_COMPILER_LLVM_PATH'] = $dir;
        }
        try {
            \PHPLLVM\Chooser::choose();
            self::$ready = true;
        } catch (\Throwable $e) {
            self::$ready = false;
        }

        return self::$ready;
    }

    /**
     * @param array<string, string> $env
     */
    public static function applyProcessEnv(array &$env, ?string $repoRoot = null): void
    {
        $dir = self::resolveDir($repoRoot);
        if (null === $dir) {
            return;
        }
        $env['PHP_COMPILER_LLVM_PATH'] = $dir;
        $ld = $env['LD_LIBRARY_PATH'] ?? '';
        $env['LD_LIBRARY_PATH'] = '' === $ld ? $dir : $dir.':'.$ld;
        $path = $env['PATH'] ?? '';
        $env['PATH'] = '' === $path ? $dir : $dir.':'.$path;
    }

    /**
     * @return list<string>
     */
    public static function envPrefix(?string $repoRoot = null): array
    {
        $dir = self::resolveDir($repoRoot);
        if (null === $dir) {
            return [];
        }
        $ld = getenv('LD_LIBRARY_PATH');
        $ldVal = false === $ld || '' === $ld ? $dir : $dir.':'.$ld;
        $path = getenv('PATH');
        $pathVal = false === $path || '' === $path ? $dir : $dir.':'.$path;

        return [
            'env',
            'LD_LIBRARY_PATH='.$ldVal,
            'PATH='.$pathVal,
            'PHP_COMPILER_LLVM_PATH='.$dir,
        ];
    }
}
