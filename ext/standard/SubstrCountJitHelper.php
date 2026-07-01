<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * substr_count() for compiled JIT/AOT modules (#14691, php-in-PHP).
 *
 * SSOT: {@see VmString::substr_count()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(substr_count)
 */
final class SubstrCountJitHelper
{
    public static function countArgv(
        string $haystack,
        string $needle,
        int $offset,
        int $length,
        int $hasLength
    ): int {
        return VmString::substr_count(
            $haystack,
            $needle,
            $offset,
            $hasLength ? $length : null
        );
    }
}
