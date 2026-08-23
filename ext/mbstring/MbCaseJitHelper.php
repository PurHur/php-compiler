<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_strtoupper() / mb_strtolower() / mb_ucfirst() / mb_lcfirst() runtime for compiled JIT/AOT
 * modules (peer MbStrwidthJitHelper #3495; ucfirst/lcfirst #34259 leftover of #27330).
 *
 * SSOT: {@see VmMbstring::strtoupper()} / {@see VmMbstring::strtolower()} /
 * {@see VmMbstring::ucfirst()} / {@see VmMbstring::lcfirst()}
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strtoupper|mb_strtolower|mb_ucfirst|mb_lcfirst)
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

    public static function ucfirstArgv(string $string, string $encoding): string
    {
        return VmMbstring::ucfirst($string, $encoding);
    }

    public static function lcfirstArgv(string $string, string $encoding): string
    {
        return VmMbstring::lcfirst($string, $encoding);
    }
}
