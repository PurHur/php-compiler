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
    public static function backtrackLimit(): int
    {
        return VmIni::getPcreBacktrackLimit();
    }
}
