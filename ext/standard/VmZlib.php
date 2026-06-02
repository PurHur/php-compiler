<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;

/** VM zlib builtins via host PHP (ext/zlib/zlib.c parity, issue #3194). */
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
        if (!\function_exists('gzcompress')) {
            return false;
        }

        $result = @\gzcompress($data, $level, $encoding);

        return false === $result ? false : $result;
    }

    public static function gzuncompress(string $data, int $maxLength = 0): string|false
    {
        if (!\function_exists('gzuncompress')) {
            return false;
        }

        $result = 0 === $maxLength ? @\gzuncompress($data) : @\gzuncompress($data, $maxLength);

        return false === $result ? false : $result;
    }

    public static function gzinflate(string $data, int $maxLength = 0): string|false
    {
        if (!\function_exists('gzinflate')) {
            return false;
        }

        $result = 0 === $maxLength ? @\gzinflate($data) : @\gzinflate($data, $maxLength);

        return false === $result ? false : $result;
    }

    public static function gzdeflate(string $data, int $level = -1, int $encoding = \ZLIB_ENCODING_RAW): string|false
    {
        if (!\function_exists('gzdeflate')) {
            return false;
        }

        $result = @\gzdeflate($data, $level, $encoding);

        return false === $result ? false : $result;
    }

    public static function gzencode(string $data, int $level = -1, int $encoding = \ZLIB_ENCODING_GZIP): string|false
    {
        if (!\function_exists('gzencode')) {
            return false;
        }

        $result = @\gzencode($data, $level, $encoding);

        return false === $result ? false : $result;
    }

    public static function gzdecode(string $data, int $maxLength = 0): string|false
    {
        if (!\function_exists('gzdecode')) {
            return false;
        }

        $result = 0 === $maxLength ? @\gzdecode($data) : @\gzdecode($data, $maxLength);

        return false === $result ? false : $result;
    }
}
