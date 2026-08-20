<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * mime_content_type() NestedJIT helper (#9236, #25544, #33034).
 *
 * Thin-AOT NestedJIT cannot call VmMime::* (empty return — peer #27079). NestedJIT
 * strncmp is unreliable (always 0) — use substr ===. No json_decode (dynamic).
 * Leaf read: host @file_get_contents (peer FileGetContentsJitHelper #29510).
 * VM SSOT remains {@see VmMime::detectFromBytes()}.
 * php-src: ext/standard/file.c — PHP_FUNCTION(mime_content_type)
 */
final class MimeContentTypeJitHelper
{
    /**
     * @return string|null null when path missing or unreadable (JIT ABI uses null __string__*)
     */
    public static function mimeContentType(string $path): ?string
    {
        $data = @\file_get_contents($path);
        if (false === $data) {
            return null;
        }

        return self::detectFromBytes($data);
    }

    /** NestedJIT-safe subset of {@see VmMime::detectFromBytes()}. */
    public static function detectFromBytes(string $data): string
    {
        $offset = 0;
        $len = \strlen($data);
        if ($len >= 3 && "\xef\xbb\xbf" === \substr($data, 0, 3)) {
            $offset = 3;
        }

        if ($len >= $offset + 5 && '<?php' === \substr($data, $offset, 5)) {
            return 'text/x-php';
        }
        if ($len >= $offset + 3 && '<?=' === \substr($data, $offset, 3)) {
            return 'text/x-php';
        }
        if ($len >= 3 && "\xff\xd8\xff" === \substr($data, 0, 3)) {
            return 'image/jpeg';
        }
        if ($len >= 16
            && "\x89PNG\r\n\x1a\n" === \substr($data, 0, 8)
            && 'IHDR' === \substr($data, 12, 4)
        ) {
            return 'image/png';
        }
        if ($len >= 6) {
            $gif = \substr($data, 0, 6);
            if ('GIF87a' === $gif || 'GIF89a' === $gif) {
                return 'image/gif';
            }
        }
        if ($len >= 5 && '%PDF-' === \substr($data, 0, 5)) {
            return 'application/pdf';
        }

        $payload = \substr($data, $offset);
        if ('' === $payload) {
            return 'application/x-empty';
        }
        if (\strlen($payload) >= 5
            && '<' === $payload[0] && '?' === $payload[1]
            && ('x' === $payload[2] || 'X' === $payload[2])
            && ('m' === $payload[3] || 'M' === $payload[3])
            && ('l' === $payload[4] || 'L' === $payload[4])
        ) {
            return 'text/xml';
        }
        if (self::looksLikePlainText($payload)) {
            return 'text/plain';
        }

        return 'application/octet-stream';
    }

    private static function looksLikePlainText(string $data): bool
    {
        $len = \strlen($data);
        if ($len < 3) {
            return false;
        }
        $checkLen = $len < 8192 ? $len : 8192;
        for ($i = 0; $i < $checkLen; ++$i) {
            $byte = \ord($data[$i]);
            if (0 === $byte || 127 === $byte) {
                return false;
            }
            if ($byte < 32 && 9 !== $byte && 10 !== $byte && 13 !== $byte) {
                return false;
            }
        }

        return true;
    }
}
