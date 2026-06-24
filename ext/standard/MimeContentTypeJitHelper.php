<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * mime_content_type() semantics for compiled JIT/AOT modules (#9236, php-in-PHP).
 *
 * SSOT: {@see VmMime::mimeContentTypeFromPath()} / {@see VmMime::detectFromBytes()}
 * php-src: ext/standard/file.c — PHP_FUNCTION(mime_content_type)
 */
final class MimeContentTypeJitHelper
{
    /**
     * @return string|null null when path missing or unreadable (JIT ABI uses null __string__*)
     */
    public static function mimeContentType(string $path): ?string
    {
        $result = VmMime::mimeContentTypeFromPath($path);
        if (false === $result) {
            return null;
        }

        return $result;
    }
}
