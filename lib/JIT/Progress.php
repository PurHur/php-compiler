<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/** Optional JIT compile progress file for native AOT segfault triage (issue #816). */
final class Progress
{
    private static bool $pathResolved = false;

    /** @var string|null */
    private static $cachedPath = null;

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
}
