<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * Resolve LLVM 9 for JIT/AOT tests (mirrors script/php-env.sh).
 */
final class LlvmToolchain
{
    private static ?bool $ready = null;

    private static ?string $readyFailure = null;

    private static bool $llvmEnvLoaded = false;

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

    /**
     * Re-apply absolute LLVM paths after phpunit.xml may force relative ./.llvm (#98).
     */
    public static function applyCurrentProcessEnv(?string $repoRoot = null): void
    {
        self::ensureLlvmEnvLoaded($repoRoot);
        $env = [];
        foreach ($_ENV as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        self::applyProcessEnv($env, $repoRoot);
        foreach (['PHP_COMPILER_LLVM_PATH', 'LD_LIBRARY_PATH', 'PATH'] as $key) {
            if (!isset($env[$key])) {
                continue;
            }
            putenv($key.'='.$env[$key]);
            $_ENV[$key] = $env[$key];
            $_SERVER[$key] = $env[$key];
        }
    }

    public static function readyFailureReason(): ?string
    {
        return self::$readyFailure;
    }

    public static function isReady(?string $repoRoot = null): bool
    {
        if (null !== self::$ready) {
            return self::$ready;
        }
        self::$readyFailure = null;
        self::ensureLlvmEnvLoaded($repoRoot);
        self::applyCurrentProcessEnv($repoRoot);
        $dir = self::resolveDir($repoRoot);
        if (null === $dir) {
            self::$ready = false;
            self::$readyFailure = 'libLLVM-9.so.1 not found under .llvm, PHP_COMPILER_LLVM_PATH, or /opt/llvm9';

            return false;
        }
        $fromEnv = getenv('PHP_COMPILER_LLVM_PATH');
        if (false === $fromEnv || '' === $fromEnv) {
            putenv('PHP_COMPILER_LLVM_PATH='.$dir);
            $_ENV['PHP_COMPILER_LLVM_PATH'] = $dir;
            $_SERVER['PHP_COMPILER_LLVM_PATH'] = $dir;
        }
        try {
            \PHPLLVM\Chooser::choose();
            self::$ready = true;
        } catch (\Throwable $e) {
            self::$ready = false;
            self::$readyFailure = 'PHPLLVM\\Chooser::choose() failed: '.$e->getMessage();
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
        $ld = self::stripRelativeLlvmPaths($ld);
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
        $ldVal = false === $ld || '' === $ld ? $dir : $dir.':'.self::stripRelativeLlvmPaths($ld);
        $path = getenv('PATH');
        $pathVal = false === $path || '' === $path ? $dir : $dir.':'.$path;

        return [
            'env',
            'LD_LIBRARY_PATH='.$ldVal,
            'PATH='.$pathVal,
            'PHP_COMPILER_LLVM_PATH='.$dir,
        ];
    }

    private static function ensureLlvmEnvLoaded(?string $repoRoot): void
    {
        if (self::$llvmEnvLoaded) {
            return;
        }
        $root = $repoRoot ?? dirname(__DIR__);
        $envFile = $root.'/src/llvm-env.php';
        if (is_file($envFile)) {
            require_once $envFile;
        }
        self::$llvmEnvLoaded = true;
    }

    private static function stripRelativeLlvmPaths(string $ld): string
    {
        if ('' === $ld) {
            return '';
        }
        $parts = array_values(array_filter(
            explode(':', $ld),
            static fn (string $part): bool => '' !== $part
                && './.llvm' !== $part
                && '.llvm' !== $part
        ));

        return implode(':', $parts);
    }
}
