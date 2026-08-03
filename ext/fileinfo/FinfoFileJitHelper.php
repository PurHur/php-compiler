<?php

declare(strict_types=1);

namespace PHPCompiler\ext\fileinfo;

/**
 * finfo_file() / finfo::file() MIME sniff for compiled JIT/AOT modules (#27196, php-in-PHP).
 *
 * NestedJIT-self-contained. Sniff mirrors {@see \PHPCompiler\ext\standard\VmMime::detectFromBytes}
 * for magics needed by the #27196 Done-when (`text/plain` for `"hello"`).
 *
 * NestedJIT hazards avoided (#27196):
 * - `\strncmp` / `\strcasecmp` with needles (false match)
 * - UTF-8 BOM / PNG binary string compares (segfault)
 *
 * php-src: ext/fileinfo/fileinfo.c — PHP_FUNCTION(finfo_file)
 */
final class FinfoFileJitHelper
{
    /**
     * @return string|null null when path missing/unreadable (JIT ABI uses null __string__*)
     */
    public static function mimeFromPath(string $path): ?string
    {
        if (!\is_readable($path)) {
            return null;
        }
        $data = @\file_get_contents($path);
        if (false === $data) {
            return null;
        }

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
