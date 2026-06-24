<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * unpack() for compiled JIT/AOT modules via UnpackEngine PHP (#9543, php-in-PHP).
 *
 * SSOT: {@see VmPack::unpackToHashTable()}; VM path uses the same engine via {@see unpack}.
 * php-src: ext/standard/pack.c — php_unpack()
 */
final class UnpackJitHelper
{
    public static function unpackArgv(string $format, string $data, int $offset): ?HashTable
    {
        return VmPack::unpackToHashTable($format, $data, $offset);
    }
}
