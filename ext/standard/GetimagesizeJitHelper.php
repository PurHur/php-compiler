<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * JIT/AOT runtime helper for getimagesize*() — reuses {@see VmImage} byte parser (#3271).
 *
 * php-src: ext/standard/image.c — PHP_FUNCTION(getimagesize), getimagesizefromstring
 */
final class GetimagesizeJitHelper
{
    public static function fromBytes(string $data): ?HashTable
    {
        $result = VmImage::getImageSizeFromBytes($data);
        if (false === $result) {
            return null;
        }

        return VmImage::imageSizeResultToHashTable($result);
    }
}
