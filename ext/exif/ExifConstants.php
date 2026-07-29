<?php

declare(strict_types=1);

namespace PHPCompiler\ext\exif;

/**
 * EXIF extension constants — php-src ext/exif/exif.c PHP_MINIT_FUNCTION(exif) (#24064).
 *
 * @see https://github.com/php/php-src/blob/PHP-8.2/ext/exif/exif.c REGISTER_LONG_CONSTANT("EXIF_USE_MBSTRING", …)
 */
final class ExifConstants
{
    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        return [
            'EXIF_USE_MBSTRING' => self::exifUseMbstring(),
        ];
    }

    /**
     * Zend: 1 when ext/mbstring is loaded at MINIT, else 0.
     */
    private static function exifUseMbstring(): int
    {
        return \extension_loaded('mbstring') ? 1 : 0;
    }
}
