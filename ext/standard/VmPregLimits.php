<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM preg_* resource limits (php-src ext/pcre/php_pcre.c).
 *
 * Holds the live backtrack limit locally so NestedJIT AOT can compile the preg
 * engine without linking {@see VmIni} (#24115 / #16075). {@see VmIni} keeps
 * ini_get/ini_set in sync via {@see self::setBacktrackLimit()}.
 */
final class VmPregLimits
{
    /** php-src PG(pcre.backtrack_limit) default 1000000. */
    private const DEFAULT_BACKTRACK_LIMIT = 1_000_000;

    /** php-src PCRE2 JIT stack depth — recursive `(?R)` fails fast with code 6. */
    private const DEFAULT_JIT_STACK_LIMIT = 32;

    private static int $backtrackLimit = self::DEFAULT_BACKTRACK_LIMIT;

    public static function backtrackLimit(): int
    {
        return self::$backtrackLimit;
    }

    public static function setBacktrackLimit(int $limit): void
    {
        self::$backtrackLimit = $limit < 0 ? self::DEFAULT_BACKTRACK_LIMIT : $limit;
    }

    public static function jitStackLimit(): int
    {
        return self::DEFAULT_JIT_STACK_LIMIT;
    }
}
