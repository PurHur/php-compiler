<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\VM\HashTable;

/**
 * iconv_mime_decode/encode/decode_headers for compiled JIT/AOT modules
 * (#27424, #31310, #34441, php-in-PHP).
 *
 * SSOT: {@see VmIconvMime::mimeDecode()} / {@see VmIconvMime::mimeEncode()} /
 * {@see VmIconvMime::mimeDecodeHeaders()}
 * php-src: ext/iconv/iconv.c — PHP_FUNCTION(iconv_mime_decode/encode/decode_headers)
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

    /**
     * @return HashTable|null null when decode fails (JIT ABI uses null __hashtable__*)
     */
    public static function mimeDecodeHeadersArgv(string $headers, int $mode, string $charset): ?HashTable
    {
        $cs = '' === $charset ? null : $charset;
        $result = VmIconvMime::mimeDecodeHeaders($headers, $mode, $cs, null);
        if (false === $result) {
            return null;
        }

        return VmIconvMime::headersResultToHashTable($result);
    }
}
