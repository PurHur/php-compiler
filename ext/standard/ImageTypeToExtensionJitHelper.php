<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * image_type_to_extension() for compiled JIT/AOT modules (#14851, php-in-PHP).
 *
 * SSOT: {@see VmImage::imageTypeToExtension()}
 * php-src: ext/standard/image.c — PHP_FUNCTION(image_type_to_extension)
 */
final class ImageTypeToExtensionJitHelper
{
    public const TAG_FALSE = 0;

    public const TAG_STRING = 1;

    private static ?string $lastString = null;

    public static function lookupArgv(int $imageType, bool $includeDot): int
    {
        $ext = VmImage::imageTypeToExtension($imageType, $includeDot);
        if (false === $ext) {
            return self::TAG_FALSE;
        }
        self::$lastString = $ext;

        return self::TAG_STRING;
    }

    public static function lastString(): string
    {
        return self::$lastString ?? '';
    }
}
