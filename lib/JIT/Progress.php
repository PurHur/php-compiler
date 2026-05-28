<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/** Optional JIT compile progress file for native AOT segfault triage (issue #816). */
final class Progress
{
    private static bool $pathResolved = false;

    /** @var string|null */
    private static $cachedPath = null;

    private static bool $phasePathResolved = false;

    /** @var string|null */
    private static $cachedPhasePath = null;

    private static bool $entryPathResolved = false;

    /** @var string|null */
    private static $cachedEntryPath = null;

    public static function noteFunction(string $name): void
    {
        if ('' === $name) {
            return;
        }
        $path = self::progressFilePath();
        if (null === $path) {
            return;
        }
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
            fwrite($fp, $name);
            fflush($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    public static function notePhase(string $phase): void
    {
        if ('' === $phase) {
            return;
        }
        $path = self::phaseFilePath();
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
        $path = self::entryFilePath();
        if (null === $path) {
            return;
        }
        self::writeBreadcrumbFile($path, $entry);
    }

    public static function readLast(?string $path = null): ?string
    {
        if (null === $path) {
            $path = self::progressFilePath();
        }
        if (null === $path || !is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if (false === $raw) {
            return null;
        }
        $line = trim($raw);

        return '' === $line ? null : $line;
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

    private static function progressFilePath(): ?string
    {
        if (self::$pathResolved) {
            return self::$cachedPath;
        }
        self::$pathResolved = true;
        $env = getenv('PHP_COMPILER_JIT_PROGRESS_FILE');
        if (false !== $env && '' !== $env) {
            self::$cachedPath = $env;

            return self::$cachedPath;
        }

        return null;
    }

    private static function phaseFilePath(): ?string
    {
        if (self::$phasePathResolved) {
            return self::$cachedPhasePath;
        }
        self::$phasePathResolved = true;
        $env = getenv('PHP_COMPILER_JIT_PHASE_FILE');
        if (false !== $env && '' !== $env) {
            self::$cachedPhasePath = $env;

            return self::$cachedPhasePath;
        }

        return null;
    }

    private static function entryFilePath(): ?string
    {
        if (self::$entryPathResolved) {
            return self::$cachedEntryPath;
        }
        self::$entryPathResolved = true;
        $env = getenv('PHP_COMPILER_JIT_ENTRY_FILE');
        if (false !== $env && '' !== $env) {
            self::$cachedEntryPath = $env;

            return self::$cachedEntryPath;
        }

        return null;
    }
}
