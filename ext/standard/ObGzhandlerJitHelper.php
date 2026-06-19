<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for ob_gzhandler() handler dispatch (#9091, php-in-PHP).
 *
 * Accept-encoding resolution uses {@see resolveEncodingFromAcceptHeader}; gzip via {@see ZlibEncodeJitHelper}.
 * php-src: ext/zlib/zlib_fopen_wrapper.c — php_ob_gzhandler
 */
final class ObGzhandlerJitHelper
{
    public static function handle(string $data, int $mode, int $encoding): string
    {
        if (0 === $encoding) {
            return self::passthrough($data, $mode);
        }

        if (0 !== ($mode & \PHP_OUTPUT_HANDLER_START)) {
            return '';
        }

        if (0 !== ($mode & (\PHP_OUTPUT_HANDLER_END | \PHP_OUTPUT_HANDLER_FINAL))) {
            if ('' === $data) {
                return '';
            }
            $compressed = ZlibEncodeJitHelper::gzencode($data, -1, $encoding);
            if (false === $compressed) {
                return $data;
            }

            return $compressed;
        }

        return '';
    }

    public static function flushBuffer(string $content, int $encoding): string
    {
        self::handle('', \PHP_OUTPUT_HANDLER_START, $encoding);
        $processed = self::handle($content, \PHP_OUTPUT_HANDLER_END, $encoding);

        return '' !== $processed ? $processed : $content;
    }

    public static function resolveEncodingFromAcceptHeader(string $accept): int
    {
        if ('' === $accept) {
            return 0;
        }
        $lower = self::asciiLower($accept);
        if (self::containsSubstring($lower, 'gzip')) {
            return \ZLIB_ENCODING_GZIP;
        }
        if (self::containsSubstring($lower, 'deflate')) {
            return \ZLIB_ENCODING_DEFLATE;
        }

        return 0;
    }

    private static function passthrough(string $data, int $mode): string
    {
        if (0 !== ($mode & \PHP_OUTPUT_HANDLER_START)) {
            return '';
        }
        if (0 !== ($mode & (\PHP_OUTPUT_HANDLER_END | \PHP_OUTPUT_HANDLER_FINAL))) {
            return $data;
        }

        return '';
    }

    private static function containsSubstring(string $haystack, string $needle): bool
    {
        $len = \strlen($needle);
        if (0 === $len) {
            return true;
        }
        $hlen = \strlen($haystack);
        if ($len > $hlen) {
            return false;
        }
        for ($i = 0; $i <= $hlen - $len; $i = $i + 1) {
            if (\substr($haystack, $i, $len) === $needle) {
                return true;
            }
        }

        return false;
    }

    private static function asciiLower(string $text): string
    {
        $out = '';
        $len = \strlen($text);
        for ($i = 0; $i < $len; $i = $i + 1) {
            $ord = \ord($text[$i]);
            if ($ord >= 65 && $ord <= 90) {
                $out .= \chr($ord + 32);
            } else {
                $out .= $text[$i];
            }
        }

        return $out;
    }
}
