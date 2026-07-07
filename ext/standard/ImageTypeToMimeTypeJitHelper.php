<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * image_type_to_mime_type() for compiled JIT/AOT modules (php-in-PHP).
 *
 * SSOT: {@see VmImage::imageTypeToMimeType()}
 * php-src: ext/standard/image.c — PHP_FUNCTION(image_type_to_mime_type)
 */
final class ImageTypeToMimeTypeJitHelper
{
    public static function mimeArgv(int $imageType): string
    {
        return VmImage::imageTypeToMimeType($imageType);
    }
}
