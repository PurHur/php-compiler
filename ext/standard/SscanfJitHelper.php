<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * sscanf() two-arg return-array path for compiled JIT/AOT modules (#9134, php-in-PHP).
 *
 * SSOT: {@see VmSscanf::parseToArray()} (php-src ext/standard/sscanf.c).
 */
final class SscanfJitHelper
{
    public static function parseToArray(string $input, string $format): ?HashTable
    {
        return VmSscanf::parseToArray($input, $format);
    }
}
