<?php

declare(strict_types=1);

namespace PHPCompiler\Cli;

/**
 * Resolve CLI paths relative to the directory where the user invoked ./phpc.
 *
 * The phpc wrapper cds to the repository root before running bin/phpc.php; export
 * PHPC_INVOKE_CWD from the wrapper so "phpc build --project ." works inside examples/.
 *
 * @see https://github.com/PurHur/php-compiler/issues/699
 */
final class InvokeCwd
{
    public static function baseDir(): ?string
    {
        $value = getenv('PHPC_INVOKE_CWD');
        if (false === $value || '' === $value) {
            return null;
        }
        $resolved = realpath($value);

        return false !== $resolved ? $resolved : $value;
    }

    /**
     * Resolve a user-supplied relative path against PHPC_INVOKE_CWD when set.
     */
    public static function resolve(string $path): string
    {
        if ('' === $path || self::isAbsolute($path)) {
            return $path;
        }
        $base = self::baseDir();
        if (null === $base) {
            return $path;
        }
        $joined = $base.'/'.$path;
        $resolved = realpath($joined);

        return false !== $resolved ? $resolved : $joined;
    }

    private static function isAbsolute(string $path): bool
    {
        if (str_starts_with($path, '/')) {
            return true;
        }

        return 'Windows' === PHP_OS_FAMILY && 1 === preg_match('#^[A-Za-z]:[/\\\\]#', $path);
    }
}
