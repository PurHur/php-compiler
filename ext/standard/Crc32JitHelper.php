<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * crc32()/crc32c() for compiled JIT/AOT modules (#15759, php-in-PHP).
 *
 * SSOT: {@see VmCrc32}, {@see VmCrc32c}
 * php-src: ext/standard/crc32.c, ext/standard/hash_crc32.c
 */
final class Crc32JitHelper
{
    public static function crc32Argv(string $data, int $seed): int
    {
        return VmCrc32::compute($data, $seed);
    }

    public static function crc32cArgv(string $data): int
    {
        return VmCrc32c::compute($data);
    }
}
