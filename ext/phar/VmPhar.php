<?php

declare(strict_types=1);

namespace PHPCompiler\ext\phar;

/**
 * Phar path helpers — php-src ext/phar/phar_object.c PHP_METHOD(Phar, running).
 */
final class VmPhar
{
    public const CLASS_LC = 'phar';

    /**
     * Resolve archive path from SCRIPT_FILENAME / phar:// URI (#3436).
     */
    public static function runningPath(string $scriptPath, bool $retPhar): string
    {
        $pharPath = self::extractPharArchivePath($scriptPath);
        if (null === $pharPath) {
            return '';
        }
        if (!$retPhar) {
            return $pharPath;
        }

        $base = basename($pharPath);
        if (str_ends_with(strtolower($base), '.phar')) {
            return substr($base, 0, -5);
        }

        return $base;
    }

    private static function extractPharArchivePath(string $path): ?string
    {
        if ('' === $path) {
            return null;
        }
        if (str_starts_with($path, 'phar://')) {
            $rest = substr($path, 7);
            $pos = stripos($rest, '.phar');
            if (false === $pos) {
                return null;
            }

            return substr($rest, 0, $pos + 5);
        }
        $pos = stripos($path, '.phar');
        if (false === $pos) {
            return null;
        }

        return substr($path, 0, $pos + 5);
    }
}
