<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Directory listing without libc scandir(3) FFI — Zend host scandir bootstrap path (#9034, #1492).
 *
 * VM under Zend PHP uses native {@see scandir()} when FFI is disabled; sorted entry list
 * matches {@see VmDirNative} / php-src ext/standard/dir.c scandir ordering.
 */
final class VmDirPure
{
    public static function available(): bool
    {
        return \function_exists('scandir');
    }

    /** @return list<string>|false */
    public static function listSorted(string $path): array|false
    {
        if (str_contains($path, "\0")) {
            return false;
        }
        if (!self::available()) {
            return false;
        }

        $entries = @\scandir($path);
        if (false === $entries || !\is_array($entries)) {
            return false;
        }

        \sort($entries, \SORT_STRING);

        /** @var list<string> $entries */
        return $entries;
    }
}
