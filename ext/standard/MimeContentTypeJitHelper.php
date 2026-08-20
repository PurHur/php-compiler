<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * mime_content_type() NestedJIT helper (#9236, #25544, #33034).
 *
 * Thin-AOT NestedJIT: host @file_get_contents (peer FileGetContentsJitHelper #29510).
 * Detection via {@see VmMime::detectFromBytes} (pure string sniff; no VmFs).
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

        return VmMime::detectFromBytes($data);
    }
}
