<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\StdlibConstants;
use PHPUnit\Framework\TestCase;

/** Issue #14993 / #22786 — ARRAY_PAD_* gated to language profile ≥ 8.4. */
final class ArrayPadPadTypeConstantsTest extends TestCase
{
    public function testArrayPadConstantsInCoreIntByName(): void
    {
        $map = StdlibConstants::CORE_INT_BY_NAME;
        self::assertSame(0, $map['array_pad_left']);
        self::assertSame(1, $map['array_pad_right']);
        self::assertSame(2, $map['array_pad_both']);
        self::assertContains('array_pad_left', StdlibConstants::CORE_FETCH_NAMES);
    }

    public function testArrayPadConstantsGatedOffOnProfile82(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            self::assertFalse(CompilerVersion::supportsArrayPadPadType());
            self::assertNull(StdlibConstants::coreIntByName('array_pad_left'));
            self::assertNull(StdlibConstants::coreIntByName('array_pad_right'));
            self::assertNull(StdlibConstants::coreIntByName('array_pad_both'));
            self::assertSame(0, StdlibConstants::coreIntByName('str_pad_left'));
        } finally {
            if (false === $prev || '' === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testArrayPadConstantsAvailableOnProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            self::assertTrue(CompilerVersion::supportsArrayPadPadType());
            self::assertSame(0, StdlibConstants::coreIntByName('array_pad_left'));
            self::assertSame(1, StdlibConstants::coreIntByName('array_pad_right'));
            self::assertSame(2, StdlibConstants::coreIntByName('array_pad_both'));
        } finally {
            if (false === $prev || '' === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testArrayPadConstantsWithheldOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            self::assertFalse(CompilerVersion::supportsArrayPadPadType());
            self::assertNull(StdlibConstants::coreIntByName('array_pad_left'));
        } finally {
            if (false === $prev || '' === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
