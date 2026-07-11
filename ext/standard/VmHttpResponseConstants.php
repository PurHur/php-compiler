<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;

/**
 * ext/standard HTTP response-code constants (php-src ext/standard/http.c, issue #18059).
 */
final class VmHttpResponseConstants
{
    /** RFC 8470 Too Early — PHP 8.4+ forward profile only. */
    public const HTTP_TOO_EARLY = 425;

    /**
     * @return array<string, int>
     */
    public static function forwardProfileIntConstants(): array
    {
        if (!CompilerVersion::supportsHttpTooEarlyConstant()) {
            return [];
        }

        return [
            'HTTP_TOO_EARLY' => self::HTTP_TOO_EARLY,
        ];
    }
}
