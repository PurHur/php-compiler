<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM preg_* resource limits from INI (php-src ext/pcre/php_pcre.c).
 *
 * SSOT: {@see VmIni} for ini_set/ini_get; default matches Zend pcre.backtrack_limit.
 */
final class VmPregLimits
{
    /** php-src PCRE2 JIT stack depth — recursive `(?R)` fails fast with code 6. */
    private const DEFAULT_JIT_STACK_LIMIT = 32;

    public static function backtrackLimit(): int
    {
        return VmIni::getPcreBacktrackLimit();
    }

    public static function jitStackLimit(): int
    {
        return self::DEFAULT_JIT_STACK_LIMIT;
    }
}
