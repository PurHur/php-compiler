<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/**
 * MIME type sniffing for mime_content_type() (php-src ext/standard/file.c; #6196, #7865).
 *
 * VM and JIT/AOT share byte sniff via detectFromBytes() — no host fileinfo or stream API delegation.
 * JIT/AOT: {@see MimeContentTypeJitHelper} via lib/JIT/Builtin/MimeContentTypeRuntime.php.
 */
final class VmMime
{
    public static function filenameOrStreamTypeErrorMessage(string $given): string
    {
        return \sprintf(
            'mime_content_type(): Argument #1 ($filename) must be of type resource|string, %s given',
            $given
        );
    }

    /**
     * @return string|false
     */
    public static function mimeContentType(Variable $operand)
    {
        $operand = $operand->resolveIndirect();
        if (Variable::TYPE_NULL === $operand->type) {
            throw new \TypeError(self::filenameOrStreamTypeErrorMessage('null'));
        }
        if (EnumCaseSupport::isEnumCaseVariable($operand)) {
            throw new \TypeError(\sprintf(
                'mime_content_type(): Argument #1 ($filename_or_stream) must be of type string, %s given',
                EnumCaseSupport::typeNameForVariable($operand)
            ));
        }

        if ($operand->isStreamResource()
            || (Variable::TYPE_INTEGER === $operand->type && VmFs::isValidHandle($operand->toInt()))) {
            return self::mimeContentTypeFromStream($operand);
        }

        $path = VmString::coerceStringBuiltinArg(
            $operand,
            'mime_content_type',
            0,
            'filename_or_stream'
        );

        return self::mimeContentTypeFromPath($path);
    }

    /**
     * @return string|false
     */
    public static function mimeContentTypeFromPath(string $path)
    {
        $data = VmFs::fileGetContents($path);
        if (false === $data) {
            return false;
        }

        return self::detectFromBytes($data);
    }

    /**
     * @return string|false
     */
    private static function mimeContentTypeFromStream(Variable $operand)
    {
        $handle = $operand->toInt();
        if (!VmFs::isValidHandle($handle)) {
            return false;
        }

        $pos = VmFs::ftell($handle);
        if (false === $pos) {
            $pos = 0;
        }
        $data = VmFs::streamGetContents($handle);
        if (false === $data) {
            return false;
        }
        if (0 !== VmFs::fseek($handle, $pos)) {
            return false;
        }

        return self::detectFromBytes($data);
    }

    /**
     * Minimal libmagic/fileinfo parity (php-src php_stream_mime_type).
     */
    public static function detectFromBytes(string $data): string
    {
        $offset = 0;
        $len = \strlen($data);
        if ($len >= 3 && "\xef\xbb\xbf" === \substr($data, 0, 3)) {
            $offset = 3;
        }

        if ($len >= $offset + 5 && 0 === \strncmp(\substr($data, $offset), '<?php', 5)) {
            return 'text/x-php';
        }
        if ($len >= $offset + 3 && 0 === \strncmp(\substr($data, $offset), '<?=', 3)) {
            return 'text/x-php';
        }
        if ($len >= 3 && 0 === \strncmp($data, "\xff\xd8\xff", 3)) {
            return 'image/jpeg';
        }
        // libmagic: bare 8-byte PNG signature is application/octet-stream; require IHDR type (#19470).
        if (self::looksLikePngWithIhdr($data)) {
            return 'image/png';
        }
        if ($len >= 6 && (0 === \strncmp($data, 'GIF87a', 6) || 0 === \strncmp($data, 'GIF89a', 6))) {
            return 'image/gif';
        }
        // libmagic: bare "%PDF" is text/plain; version dash required ("%PDF-…") (#25197).
        if (self::looksLikePdf($data)) {
            return 'application/pdf';
        }

        $payload = \substr($data, $offset);
        if ('' === $payload) {
            return 'application/x-empty';
        }
        // XML PI is matched before HTML; leading whitespace before <?xml is plain (libmagic).
        if (self::looksLikeXml($payload)) {
            return 'text/xml';
        }
        if (self::looksLikeHtml($payload)) {
            return 'text/html';
        }
        if (self::looksLikeSvg($payload)) {
            return 'image/svg+xml';
        }
        // JSON magic ignores UTF-8 BOM (BOM+JSON → text/plain in Zend/libmagic).
        if (0 === $offset && self::looksLikeJson($payload)) {
            return 'application/json';
        }
        if (self::looksLikePlainText($payload)) {
            return 'text/plain';
        }

        return 'application/octet-stream';
    }

    /**
     * libmagic PNG: signature alone is not enough — first chunk type must be IHDR (#19470).
     * Minimum: 8-byte signature + 4-byte length + "IHDR" (16 bytes).
     */
    private static function looksLikePngWithIhdr(string $data): bool
    {
        return \strlen($data) >= 16
            && 0 === \strncmp($data, "\x89PNG\r\n\x1a\n", 8)
            && 'IHDR' === \substr($data, 12, 4);
    }

    /**
     * libmagic PDF: "%PDF-" (case-sensitive); bare "%PDF" is text/plain (#25197).
     */
    private static function looksLikePdf(string $data): bool
    {
        return \strlen($data) >= 5 && 0 === \strncmp($data, '%PDF-', 5);
    }

    /**
     * libmagic XML PI heuristic (Magdir/sgml; #19353).
     * Requires <?xml at byte 0 of the post-BOM payload (no leading whitespace).
     */
    private static function looksLikeXml(string $data): bool
    {
        return \strlen($data) >= 5 && 0 === \strncasecmp($data, '<?xml', 5);
    }

    /**
     * libmagic SVG heuristic — lowercase <svg tag only (#19353).
     */
    private static function looksLikeSvg(string $data): bool
    {
        $trim = \ltrim($data);
        if (\strlen($trim) < 4 || 0 !== \strncmp($trim, '<svg', 4)) {
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
     * libmagic JSON object/array heuristic (#19353).
     * Accepts trailing commas (libmagic); rejects incomplete `{`/`[` (falls through).
     */
    private static function looksLikeJson(string $data): bool
    {
        $trim = \ltrim($data);
        if ('' === $trim) {
            return false;
        }
        $start = $trim[0];
        if ('{' !== $start && '[' !== $start) {
            return false;
        }
        if (\strlen($trim) < 2) {
            return false;
        }
        if (self::jsonDecodeOk($trim)) {
            return true;
        }
        // libmagic tolerates a trailing comma before } or ].
        $relaxed = \preg_replace('/,\s*([}\]])/', '$1', $trim);
        if (!\is_string($relaxed) || $relaxed === $trim) {
            return false;
        }

        return self::jsonDecodeOk($relaxed);
    }

    private static function jsonDecodeOk(string $json): bool
    {
        \json_decode($json);
        if (\JSON_ERROR_NONE !== \json_last_error()) {
            return false;
        }
        // Objects/arrays only — scalars never start with { or [.
        return true;
    }

    /** libmagic HTML heuristic (php-src ext/fileinfo; #19247). */
    private static function looksLikeHtml(string $data): bool
    {
        $trim = \ltrim($data);
        if ('' === $trim) {
            return false;
        }
        if (0 === \strncasecmp($trim, '<!DOCTYPE', 9)) {
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

    /** libmagic ASCII/UTF-8 text heuristic (php-src ext/fileinfo; #12116). */
    private static function looksLikePlainText(string $data): bool
    {
        $len = \strlen($data);
        // libmagic: samples shorter than 3 bytes stay application/octet-stream (#23200).
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
