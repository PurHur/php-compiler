<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\VmImage;
use PHPUnit\Framework\TestCase;

/** Issue #22787 — IMAGETYPE_HEIF gated to language profile ≥ 8.5. */
final class ImagTypeHeifConstantsTest extends TestCase
{
    public function testHeifWithheldOnProfile82(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            self::assertFalse(CompilerVersion::supportsImagTypeHeif());
            $c = VmImage::constants();
            self::assertArrayNotHasKey('IMAGETYPE_HEIF', $c);
            self::assertSame(VmImage::IMAGETYPE_COUNT_PRE_HEIF, $c['IMAGETYPE_COUNT']);
            self::assertSame(VmImage::IMAGETYPE_AVIF, $c['IMAGETYPE_AVIF']);
        } finally {
            if (false === $prev || '' === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testHeifAvailableOnProfile85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            self::assertTrue(CompilerVersion::supportsImagTypeHeif());
            $c = VmImage::constants();
            self::assertSame(VmImage::IMAGETYPE_HEIF, $c['IMAGETYPE_HEIF']);
            self::assertSame(VmImage::IMAGETYPE_COUNT, $c['IMAGETYPE_COUNT']);
        } finally {
            if (false === $prev || '' === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testHeifWithheldOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            self::assertFalse(CompilerVersion::supportsImagTypeHeif());
            self::assertArrayNotHasKey('IMAGETYPE_HEIF', VmImage::constants());
        } finally {
            if (false === $prev || '' === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
