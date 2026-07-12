<?php

declare(strict_types=1);

namespace PHPCompiler\ext\exif;

use PHPCompiler\ext\standard\TriggerErrorJitHelper;
use PHPCompiler\ext\standard\VmImage;

/**
 * exif_imagetype() for compiled JIT/AOT modules (#18181, php-in-PHP).
 *
 * SSOT: {@see VmExifRead::imageType()} + {@see exif_imagetype::execute()} warning parity.
 * php-src: ext/exif/exif.c — PHP_FUNCTION(exif_imagetype)
 */
final class ExifImagetypeJitHelper
{
    /**
     * @return int IMAGETYPE_* constant on success, -1 on failure (maps to bool false in LLVM bridge)
     */
    public static function fromPath(string $filename): int
    {
        $type = VmExifRead::imageType($filename);
        if (false === $type) {
            if (!VmImage::pathPayloadReadable($filename)) {
                TriggerErrorJitHelper::warning(\sprintf(
                    'exif_imagetype(%s): Failed to open stream: No such file or directory',
                    $filename
                ));
            }

            return -1;
        }

        return (int) $type;
    }
}
