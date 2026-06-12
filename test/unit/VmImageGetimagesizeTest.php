<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmImage;
use PHPUnit\Framework\TestCase;

/** VmImage::getImageSizeFromBytes() parity probes (#3271). */
final class VmImageGetimagesizeTest extends TestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function testPngOneByOneMatchesZendShape(): void
    {
        $png = base64_decode(self::PNG_1X1, true);
        self::assertIsString($png);
        $info = VmImage::getImageSizeFromBytes($png);
        self::assertIsArray($info);
        self::assertSame(1, $info[0]);
        self::assertSame(1, $info[1]);
        self::assertSame(VmImage::IMAGETYPE_PNG, $info[2]);
        self::assertSame('width="1" height="1"', $info[3]);
        self::assertSame(8, $info['bits']);
        self::assertSame('image/png', $info['mime']);
        self::assertArrayNotHasKey('channels', $info);
    }

    public function testInvalidBytesReturnFalse(): void
    {
        self::assertFalse(VmImage::getImageSizeFromBytes('not-an-image'));
    }
}
