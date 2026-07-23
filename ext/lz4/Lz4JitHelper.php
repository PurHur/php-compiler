<?php

declare(strict_types=1);

namespace PHPCompiler\ext\lz4;

/**
 * lz4_compress()/lz4_uncompress() for VM, JIT, and AOT (#22529, php-in-PHP).
 */
final class Lz4JitHelper
{
    public static function compress(string $data, int $level = 0): ?string
    {
        $result = VmLz4Native::compress($data, $level);

        return false === $result ? null : $result;
    }

    public static function uncompress(string $data, int $max = -1, int $offset = 0): ?string
    {
        $result = VmLz4Native::uncompress($data, $max, $offset);

        return false === $result ? null : $result;
    }
}
