<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_strtoupper() / mb_strtolower() runtime for compiled JIT/AOT modules (peer MbStrwidthJitHelper #3495).
 *
 * SSOT: {@see VmMbstring::strtoupper()} / {@see VmMbstring::strtolower()}
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strtoupper), PHP_FUNCTION(mb_strtolower)
 */
final class MbCaseJitHelper
{
    public static function strtoupperArgv(string $string, string $encoding): string
    {
        return VmMbstring::strtoupper($string, $encoding);
    }

    public static function strtolowerArgv(string $string, string $encoding): string
    {
        return VmMbstring::strtolower($string, $encoding);
    }
}
