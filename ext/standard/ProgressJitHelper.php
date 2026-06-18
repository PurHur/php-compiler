<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for progress breadcrumbs (#9521, php-in-PHP).
 *
 * SSOT for env-file progress writes; VM path delegates via {@see \PHPCompiler\JIT\Progress}.
 * SIGSEGV buffer (phpc_last_progress) stays in LLVM — see ProgressNoteRuntime.php.
 */
final class ProgressJitHelper
{
    private const ENV_PROGRESS = 'PHP_COMPILER_JIT_PROGRESS_FILE';

    private const ENV_PHASE = 'PHP_COMPILER_JIT_PHASE_FILE';

    private const ENV_ENTRY = 'PHP_COMPILER_JIT_ENTRY_FILE';

    /** Mirrors __phpc_progress_note LLVM: same message to all three env files (#6748). */
    public static function noteBroadcast(string $message): void
    {
        if ('' === $message) {
            return;
        }
        self::writeEnvFile(self::ENV_PROGRESS, $message);
        self::writeEnvFile(self::ENV_PHASE, $message);
        self::writeEnvFile(self::ENV_ENTRY, $message);
    }

    public static function noteFunction(string $name): void
    {
        if ('' === $name) {
            return;
        }
        $path = self::envPath(self::ENV_PROGRESS);
        if (null === $path) {
            return;
        }
        self::writeBreadcrumbFile($path, $name);
    }

    public static function notePhase(string $phase): void
    {
        if ('' === $phase) {
            return;
        }
        $path = self::envPath(self::ENV_PHASE);
        if (null === $path) {
            return;
        }
        self::writeBreadcrumbFile($path, $phase);
    }

    public static function noteEntry(string $entry): void
    {
        if ('' === $entry) {
            return;
        }
        $path = self::envPath(self::ENV_ENTRY);
        if (null === $path) {
            return;
        }
        self::writeBreadcrumbFile($path, $entry);
    }

    private static function writeEnvFile(string $envKey, string $content): void
    {
        $path = self::envPath($envKey);
        if (null === $path) {
            return;
        }
        self::writeBreadcrumbFile($path, $content);
    }

    private static function envPath(string $envKey): ?string
    {
        $env = getenv($envKey);
        if (false === $env || '' === $env) {
            return null;
        }

        return $env;
    }

    private static function writeBreadcrumbFile(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fp = @fopen($path, 'cb');
        if (false === $fp) {
            return;
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                return;
            }
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $content);
            fflush($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
