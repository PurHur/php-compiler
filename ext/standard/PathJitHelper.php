<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * dirname()/basename() for compiled JIT/AOT modules (#15286, php-in-PHP).
 *
 * SSOT: {@see VmString::dirname()} / {@see VmString::basename()}
 * php-src: ext/standard/dir.c, ext/standard/basename.c
 */
final class PathJitHelper
{
    public static function dirnameArgv(string $path): string
    {
        return VmString::dirname($path, 1);
    }

    public static function dirnameWithLevelsArgv(string $path, int $levels): string
    {
        return VmString::dirname($path, $levels);
    }

    public static function basenameArgv(string $path): string
    {
        return VmString::basename($path);
    }

    public static function basenameWithSuffixArgv(string $path, string $suffix): string
    {
        return VmString::basename($path, $suffix);
    }
}
