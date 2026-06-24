<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/**
 * MIME type sniffing for mime_content_type() (php-src ext/standard/file.c; #6196, #7865).
 *
 * VM and JIT/AOT share byte sniff via detectFromBytes() — no host fileinfo delegation.
 * JIT/AOT: {@see MimeContentTypeJitHelper} via lib/JIT/Builtin/MimeContentTypeRuntime.php.
 */
final class VmMime
{
    /**
     * @return string|false
     */
    public static function mimeContentType(Variable $operand)
    {
        $operand = $operand->resolveIndirect();
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
        $handle = $operand->isStreamResource()
            ? $operand->toInt()
            : $operand->toInt();
        $fp = VmFs::lookupResource($handle);
        if (null === $fp) {
            return false;
        }

        $pos = @\ftell($fp);
        if (false === $pos) {
            $pos = 0;
        }
        $data = @\stream_get_contents($fp);
        if (false === $data) {
            return false;
        }
        if (0 !== @\fseek($fp, $pos)) {
            return false;
        }

        return self::detectFromBytes($data);
    }

    /**
     * Minimal libmagic/fileinfo parity for JIT/AOT and host fallback (php-src php_stream_mime_type).
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
        if ($len >= 8 && 0 === \strncmp($data, "\x89PNG\r\n\x1a\n", 8)) {
            return 'image/png';
        }
        if ($len >= 6 && (0 === \strncmp($data, 'GIF87a', 6) || 0 === \strncmp($data, 'GIF89a', 6))) {
            return 'image/gif';
        }
        if ($len >= 4 && 0 === \strncmp($data, '%PDF', 4)) {
            return 'application/pdf';
        }

        return 'application/octet-stream';
    }
}
