<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;

/**
 * HTTP response-code constants (php-src ext/standard/http.c REGISTER_LONG_CONSTANT).
 */
final class VmHttpConstants
{
    /** RFC 8470 early-data response (PHP 8.3+, issue #18059). */
    public const HTTP_TOO_EARLY = 425;

    /** @return array<string, int> */
    public static function constants(): array
    {
        if (!CompilerVersion::supportsHttpTooEarlyConstant()) {
            return [];
        }

        return [
            'HTTP_TOO_EARLY' => self::HTTP_TOO_EARLY,
        ];
    }
}
