<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * File path predicates for compiled JIT/AOT modules (#9112, php-in-PHP).
 *
 * VM SSOT: {@see VmStatPath}
 * php-src: ext/standard/filestat.c
 */
final class StatPathJitHelper
{
    public static function exists(string $path): bool
    {
        return VmStatPath::exists($path);
    }

    public static function isFile(string $path): bool
    {
        return VmStatPath::isFile($path);
    }

    public static function isDir(string $path): bool
    {
        return VmStatPath::isDir($path);
    }

    public static function isLink(string $path): bool
    {
        return VmStatPath::isLink($path);
    }

    public static function isReadable(string $path): bool
    {
        return VmStatPath::isReadable($path);
    }

    public static function isWritable(string $path): bool
    {
        return VmStatPath::isWritable($path);
    }

    public static function isExecutable(string $path): bool
    {
        return VmStatPath::isExecutable($path);
    }
}
