<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * mime_content_type() semantics for compiled JIT/AOT modules (#9236, #33034, #33039, php-in-PHP).
 *
 * Read via `@file_get_contents` → NestedJIT whitelist → {@see JitFileGetContentsLibc}
 * (peer FileGetContentsJitHelper #29833).
 *
 * Sniff logic is inlined in this file: NestedJIT helper compile only covers HELPER_PATH
 * (cross-file VmMime detectFromBytes returns null under AOT). NestedJIT `strncmp` /
 * `strncasecmp` always return 0 (#33039) — use `substr` === / strtolower compares.
 * php-src: ext/standard/file.c — PHP_FUNCTION(mime_content_type)
 */
final class MimeContentTypeJitHelper
{
    /**
     * @return string|null null when path missing or unreadable (JIT ABI uses null __string__*)
     */
    public static function mimeContentType(string $path): ?string
    {
        // NestedJIT libc open rejects data: — peer FileGetContentsJitHelper (#34731 / #34789).
        if (\is_string($path) && 'data:' === \substr($path, 0, 5)) {
            $data = self::decodeDataUri($path);
            if (null === $data) {
                return null;
            }

            return self::detectFromBytes($data);
        }
        $data = @\file_get_contents($path);
        if (false === $data) {
            return null;
        }

        return self::detectFromBytes($data);
    }

    /**
     * NestedJIT-safe subset of {@see VmDataUri::decode} (#34731 / #34789).
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

    /** Mirror VmMime detectFromBytes — NestedJIT same-file only (#33034 / #33039). */
    private static function detectFromBytes(string $data): string
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
        if (self::looksLikePngWithIhdr($data)) {
            return 'image/png';
        }
        if ($len >= 6) {
            $gif = \substr($data, 0, 6);
            if ('GIF87a' === $gif || 'GIF89a' === $gif) {
                return 'image/gif';
            }
        }
        if (self::looksLikePdf($data)) {
            return 'application/pdf';
        }

        $payload = \substr($data, $offset);
        if ('' === $payload) {
            return 'application/x-empty';
        }
        if (self::looksLikeXml($payload)) {
            return 'text/xml';
        }
        if (self::looksLikeHtml($payload)) {
            return 'text/html';
        }
        if (self::looksLikeSvg($payload)) {
            return 'image/svg+xml';
        }
        if (0 === $offset && self::looksLikeJson($payload)) {
            return 'application/json';
        }
        if (self::looksLikePlainText($payload)) {
            return 'text/plain';
        }

        return 'application/octet-stream';
    }

    private static function looksLikePngWithIhdr(string $data): bool
    {
        return \strlen($data) >= 16
            && "\x89PNG\r\n\x1a\n" === \substr($data, 0, 8)
            && 'IHDR' === \substr($data, 12, 4);
    }

    private static function looksLikePdf(string $data): bool
    {
        return \strlen($data) >= 5 && '%PDF-' === \substr($data, 0, 5);
    }

    private static function looksLikeXml(string $data): bool
    {
        return \strlen($data) >= 5 && '<?xml' === \strtolower(\substr($data, 0, 5));
    }

    private static function looksLikeSvg(string $data): bool
    {
        $trim = \ltrim($data);
        if (\strlen($trim) < 4 || '<svg' !== \substr($trim, 0, 4)) {
            return false;
        }
        if (4 === \strlen($trim)) {
            return true;
        }
        $next = $trim[4];

        return ' ' === $next || '>' === $next || '/' === $next || "\t" === $next
            || "\n" === $next || "\r" === $next;
    }

    /**
     * NestedJIT cannot call json_decode on dynamic strings (compile-time literal only).
     * VM SSOT keeps the full JSON heuristic; AOT falls through to text/plain /
     * octet-stream for JSON-shaped payloads (#33034).
     */
    private static function looksLikeJson(string $data): bool
    {
        return false;
    }

    private static function looksLikeHtml(string $data): bool
    {
        $trim = \ltrim($data);
        if ('' === $trim) {
            return false;
        }
        if ('<!doctype' === \strtolower(\substr($trim, 0, 9))) {
            return false !== \stripos(\substr($trim, 0, 256), 'html');
        }
        if ('<' !== $trim[0]) {
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
        $checkLen = \min($len, 8192);
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
