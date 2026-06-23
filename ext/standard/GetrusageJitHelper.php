<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * getrusage() for compiled JIT/AOT modules (#9184, php-in-PHP).
 *
 * SSOT: {@see VmProcess::getrusage} → {@see VmGetrusageNative} / {@see VmGetrusagePure}.
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(getrusage)
 */
final class GetrusageJitHelper
{
    public static function resolve(int $who): ?HashTable
    {
        $usage = VmProcess::getrusage($who);

        return false === $usage ? null : $usage;
    }
}
