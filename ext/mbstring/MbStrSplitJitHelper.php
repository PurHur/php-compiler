<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\VM\HashTable;

/**
 * mb_str_split() for compiled JIT/AOT modules (#26870, php-in-PHP).
 *
 * SSOT: {@see VmMbstring::strSplit()}
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_str_split)
 */
final class MbStrSplitJitHelper
{
    public static function strSplitArgv(string $string, int $length, string $encoding): HashTable
    {
        return MbstringState::hashTableFromStringList(
            VmMbstring::strSplit($string, $length, $encoding)
        );
    }
}
