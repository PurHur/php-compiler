<?php

declare(strict_types=1);

namespace PHPCompiler\ext\fileinfo;

/**
 * finfo_file() / finfo_buffer() MIME sniff for compiled JIT/AOT modules (#27196, #28660, php-in-PHP).
 *
 * NestedJIT-self-contained. Sniff mirrors {@see \PHPCompiler\ext\standard\VmMime::detectFromBytes}
 * for magics needed by the Done-when (`text/plain` for `"hello"`).
 *
 * NestedJIT hazards avoided (#27196):
 * - `\strncmp` / `\strcasecmp` with needles (false match)
 * - UTF-8 BOM / PNG binary string compares (segfault) — PNG uses byte-wise ord (#34797)
 *
 * `data:` URIs must not hit `is_readable` / libc open — NestedJIT-safe decode (peer #34731 /
 * #34789 / MimeContentTypeJitHelper, php_data_wrapper.c). php-src opens with
 * php_stream_open_wrapper so allow_url_fopen applies and data:// is allowed by default.
 *
 * php-src: ext/fileinfo/fileinfo.c — PHP_FUNCTION(finfo_file) / PHP_FUNCTION(finfo_buffer)
 */
final class FinfoFileJitHelper
{
    /**
     * @return string|null null when path missing/unreadable (JIT ABI uses null __string__*)
     */
    public static function mimeFromPath(string $path): ?string
    {
        // NestedJIT libc open / is_readable reject data: — peer MimeContentType (#34731 / #34797).
        if (\is_string($path) && 'data:' === \substr($path, 0, 5)) {
            $data = self::decodeDataUri($path);
            if (null === $data) {
                return null;
            }

            return self::detectFromBytes($data);
        }
        if (!\is_readable($path)) {
            return null;
        }
        $data = @\file_get_contents($path);
        if (false === $data) {
            return null;
        }

        return self::detectFromBytes($data);
    }

    /**
     * NestedJIT-safe subset of {@see \PHPCompiler\ext\standard\VmDataUri::decode} (#34731 / #34797).
     * Same-file only — NestedJIT cannot call cross-helper decode reliably.
     */
    private static function decodeDataUri(string $path): ?string
    {
        $comma = \strrpos($path, ',');
        if (false === $comma) {
            return null;
        }
        $data = \substr($path, $comma + 1);
        if (false !== \stripos($path, ';base64,')) {
            $decoded = \base64_decode($data, true);

            return false === $decoded ? null : $decoded;
        }

        return $data;
    }

    /**
     * finfo_buffer() / finfo::buffer() — sniff in-memory bytes (#28660).
     *
     * Always returns a MIME string (peer {@see \PHPCompiler\ext\fileinfo\VmFinfo::buffer}).
     */
    public static function mimeFromBuffer(string $data): string
    {
        return self::detectFromBytes($data);
    }

    public static function detectFromBytes(string $data): string
    {
        $len = \strlen($data);
        if (0 === $len) {
            return 'application/x-empty';
        }

        if ($len >= 5 && '<?php' === \substr($data, 0, 5)) {
            return 'text/x-php';
        }
        if ($len >= 3 && '<?=' === \substr($data, 0, 3)) {
            return 'text/x-php';
        }
        // Byte-wise JPEG SOI — NestedJIT binary `\strncmp` false-matches (#27196).
        if ($len >= 3 && 0xff === \ord($data[0]) && 0xd8 === \ord($data[1]) && 0xff === \ord($data[2])) {
            return 'image/jpeg';
        }
        // PNG signature + IHDR — byte-wise (NestedJIT binary string compare hazard #27196 / #34797).
        if (self::looksLikePngWithIhdr($data)) {
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
        // Prefer strtolower+=== over strcasecmp under NestedJIT (#27196).
        if ($len >= 5 && '<?xml' === \strtolower(\substr($data, 0, 5))) {
            return 'text/xml';
        }
        if (self::looksLikeHtml($data)) {
            return 'text/html';
        }
        if (self::looksLikePlainText($data)) {
            return 'text/plain';
        }

        return 'application/octet-stream';
    }

    private static function looksLikePngWithIhdr(string $data): bool
    {
        if (\strlen($data) < 16) {
            return false;
        }
        // \x89PNG\r\n\x1a\n — ord() only (NestedJIT binary literals #27196).
        if (0x89 !== \ord($data[0]) || 0x50 !== \ord($data[1]) || 0x4e !== \ord($data[2]) || 0x47 !== \ord($data[3])) {
            return false;
        }
        if (0x0d !== \ord($data[4]) || 0x0a !== \ord($data[5]) || 0x1a !== \ord($data[6]) || 0x0a !== \ord($data[7])) {
            return false;
        }

        return 'IHDR' === \substr($data, 12, 4);
    }

    private static function looksLikeHtml(string $data): bool
    {
        $trim = \ltrim($data);
        if ('' === $trim || '<' !== $trim[0]) {
            return false;
        }
        $head = \strtolower(\substr($trim, 0, 64));

        return 0 === \strpos($head, '<html')
            || 0 === \strpos($head, '<head')
            || 0 === \strpos($head, '<body')
            || 0 === \strpos($head, '<script')
            || 0 === \strpos($head, '<table');
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
