<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_preferred_mime_name() NestedJIT runtime (#34298 leftover of #13100).
 *
 * SSOT: {@see MbstringEncodingRegistry::preferredMimeName()}
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_preferred_mime_name)
 */
final class MbPreferredMimeNameJitHelper
{
    /**
     * @return string|false
     */
    public static function preferredArgv(string $encoding)
    {
        $canonical = MbstringEncodingRegistry::assertValid($encoding, 'mb_preferred_mime_name', 0);
        $mime = MbstringEncodingRegistry::preferredMimeName($canonical);
        if (false === $mime) {
            trigger_error(
                sprintf('No MIME preferred name corresponding to "%s"', $canonical),
                \E_USER_WARNING
            );

            return false;
        }

        return $mime;
    }
}
