<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;

/**
 * VM zlib builtins via libz FFI (ext/zlib/zlib.c parity, issue #3194, #6476).
 *
 * JIT/AOT use {@see \PHPCompiler\JIT\Builtin\StringZlibJit}; VM uses {@see VmZlibNative}.
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
        if (VmZlibNative::available()) {
            return VmZlibNative::gzcompress($data, $level, $encoding);
        }
        if (self::hostGzDisabled()) {
            return false;
        }

        return self::hostGzcompress($data, $level, $encoding);
    }

    public static function gzuncompress(string $data, int $maxLength = 0): string|false
    {
        if (VmZlibNative::available()) {
            return VmZlibNative::gzuncompress($data, $maxLength);
        }
        if (self::hostGzDisabled()) {
            return false;
        }

        return 0 === $maxLength ? @\gzuncompress($data) : @\gzuncompress($data, $maxLength);
    }

    public static function gzinflate(string $data, int $maxLength = 0): string|false
    {
        if (VmZlibNative::available()) {
            return VmZlibNative::gzinflate($data, $maxLength);
        }
        if (self::hostGzDisabled()) {
            return false;
        }

        return 0 === $maxLength ? @\gzinflate($data) : @\gzinflate($data, $maxLength);
    }

    public static function gzdeflate(string $data, int $level = -1, int $encoding = \ZLIB_ENCODING_RAW): string|false
    {
        if (VmZlibNative::available()) {
            return VmZlibNative::gzdeflate($data, $level, $encoding);
        }
        if (self::hostGzDisabled()) {
            return false;
        }

        return @\gzdeflate($data, $level, $encoding);
    }

    public static function gzencode(string $data, int $level = -1, int $encoding = \ZLIB_ENCODING_GZIP): string|false
    {
        if (VmZlibNative::available()) {
            return VmZlibNative::gzencode($data, $level, $encoding);
        }
        if (self::hostGzDisabled()) {
            return false;
        }

        return @\gzencode($data, $level, $encoding);
    }

    public static function gzdecode(string $data, int $maxLength = 0): string|false
    {
        if (VmZlibNative::available()) {
            return VmZlibNative::gzdecode($data, $maxLength);
        }
        if (self::hostGzDisabled()) {
            return false;
        }

        return 0 === $maxLength ? @\gzdecode($data) : @\gzdecode($data, $maxLength);
    }

    private static function hostGzDisabled(): bool
    {
        $flag = getenv('PHP_COMPILER_DISABLE_HOST_GZ');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    private static function hostGzcompress(string $data, int $level, int $encoding): string|false
    {
        if (!\function_exists('gzcompress')) {
            return false;
        }
        $result = @\gzcompress($data, $level, $encoding);

        return false === $result ? false : $result;
    }
}
