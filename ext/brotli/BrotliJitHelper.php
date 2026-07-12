<?php

declare(strict_types=1);

namespace PHPCompiler\ext\brotli;

/**
 * brotli_compress()/brotli_uncompress() for VM, JIT, and AOT (#6814, php-in-PHP).
 */
final class BrotliJitHelper
{
    /** Defaults match VmBrotliNative::DEFAULT_QUALITY (11) and MODE_GENERIC (0) — literals for self-host (#3803). */
    public static function compress(string $data, int $level = 11, int $mode = 0): ?string
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
