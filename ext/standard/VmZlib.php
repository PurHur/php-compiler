<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;

/**
 * VM zlib builtins via libz FFI (ext/zlib/zlib.c parity, issue #3194, #6476, #6356).
 *
 * JIT/AOT use {@see \PHPCompiler\JIT\Builtin\ZlibRuntime} → {@see ZlibJitHelper}; VM uses {@see VmZlibNative}.
 */
final class VmZlib
{
    public static function triggerWarning(Frame $frame, string $fallbackMessage): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $last = error_get_last();
        $message = $fallbackMessage;
        if (\is_array($last) && isset($last['message'])) {
            $message = $last['message'];
        }
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    public static function gzcompress(string $data, int $level = -1, int $encoding = \ZLIB_ENCODING_DEFLATE): string|false
    {
        return VmZlibNative::gzcompress($data, $level, $encoding);
    }

    public static function gzuncompress(string $data, int $maxLength = 0): string|false
    {
        return VmZlibNative::gzuncompress($data, $maxLength);
    }

    public static function gzinflate(string $data, int $maxLength = 0): string|false
    {
        return VmZlibNative::gzinflate($data, $maxLength);
    }

    public static function gzdeflate(string $data, int $level = -1, int $encoding = \ZLIB_ENCODING_RAW): string|false
    {
        return VmZlibNative::gzdeflate($data, $level, $encoding);
    }

    public static function gzencode(string $data, int $level = -1, int $encoding = \ZLIB_ENCODING_GZIP): string|false
    {
        return VmZlibNative::gzencode($data, $level, $encoding);
    }

    public static function gzdecode(string $data, int $maxLength = 0): string|false
    {
        return VmZlibNative::gzdecode($data, $maxLength);
    }

    public static function zlib_encode(string $data, int $encoding, int $level = -1): string|false
    {
        return VmZlibNative::zlib_encode($data, $encoding, $level);
    }

    public static function zlib_decode(string $data, int $maxLength = 0): string|false
    {
        return VmZlibNative::zlib_decode($data, $maxLength);
    }
}
