<?php

declare(strict_types=1);

namespace PHPCompiler\ext\brotli;

/**
 * brotli_compress()/brotli_uncompress() for VM, JIT, and AOT (#6814, php-in-PHP).
 */
final class BrotliJitHelper
{
    public static function compress(string $data, int $level = VmBrotliNative::DEFAULT_QUALITY, int $mode = VmBrotliNative::MODE_GENERIC): ?string
    {
        $result = VmBrotliNative::compress($data, $level, $mode);

        return false === $result ? null : $result;
    }

    public static function uncompress(string $data): ?string
    {
        $result = VmBrotliNative::uncompress($data);

        return false === $result ? null : $result;
    }
}
