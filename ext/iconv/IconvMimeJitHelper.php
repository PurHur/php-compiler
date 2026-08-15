<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

/**
 * iconv_mime_decode/encode for compiled JIT/AOT modules (#27424, #31310, php-in-PHP).
 *
 * SSOT: {@see VmIconvMime::mimeDecode()} / {@see VmIconvMime::mimeEncode()}
 * php-src: ext/iconv/iconv.c — PHP_FUNCTION(iconv_mime_decode/encode)
 */
final class IconvMimeJitHelper
{
    /**
     * @return string|null null when decode fails (JIT ABI uses null __string__*)
     */
    public static function mimeDecodeArgv(string $encoded, int $mode, string $charset): ?string
    {
        $cs = '' === $charset ? null : $charset;
        $result = VmIconvMime::mimeDecode($encoded, $mode, $cs, null);
        if (false === $result) {
            return null;
        }

        return $result;
    }

    /**
     * @param string $prefsJson empty = omitted options; otherwise JSON object of preferences
     *
     * @return string|null null when encode fails (JIT ABI uses null __string__*)
     */
    public static function mimeEncodeArgv(string $fieldName, string $fieldValue, string $prefsJson): ?string
    {
        $preferences = null;
        if ('' !== $prefsJson) {
            $decoded = \json_decode($prefsJson, true);
            if (!\is_array($decoded)) {
                return null;
            }
            $preferences = $decoded;
        }
        $result = VmIconvMime::mimeEncode($fieldName, $fieldValue, $preferences, null);
        if (false === $result) {
            return null;
        }

        return $result;
    }
}
