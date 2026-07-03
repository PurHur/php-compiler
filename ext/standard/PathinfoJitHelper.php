<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * pathinfo() extension/filename components for compiled JIT/AOT modules (#15322, php-in-PHP).
 *
 * SSOT: {@see VmString::pathExtension()} / {@see VmString::pathFilename()} / {@see VmString::pathinfo()}
 * php-src: ext/standard/basic_functions.c — php_pathinfo
 */
final class PathinfoJitHelper
{
    public static function extensionArgv(string $path): string
    {
        return VmString::pathExtension($path);
    }

    public static function filenameArgv(string $path): string
    {
        return VmString::pathFilename($path);
    }

    public static function componentArgv(string $path, int $mask): string
    {
        $result = VmString::pathinfo($path, $mask & 15);
        if (!\is_string($result)) {
            throw new \LogicException('pathinfo() component mask must yield string in JIT helper');
        }

        return $result;
    }
}
