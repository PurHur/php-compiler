<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\StdlibConstants;
use PHPUnit\Framework\TestCase;

/** Issue #14993 / #22786 / #24002 — ARRAY_PAD_* never advertised (php-src-strict). */
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

    public function testArrayPadPadTypeGateAlwaysOff(): void
    {
        self::assertFalse(CompilerVersion::supportsArrayPadPadType());
        self::assertFalse(CompilerVersion::supportsArrayPadTypeEnum());
    }

    /**
     * @dataProvider provideProfiles
     */
    public function testArrayPadConstantsWithheldOnEveryProfile(string $profile): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        if ('' === $profile) {
            putenv('PHP_COMPILER_PROFILE');
        } else {
            putenv('PHP_COMPILER_PROFILE='.$profile);
        }
        try {
            self::assertFalse(CompilerVersion::supportsArrayPadPadType());
            self::assertFalse(CompilerVersion::supportsArrayPadTypeEnum());
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

    /** @return iterable<string, array{0: string}> */
    public static function provideProfiles(): iterable
    {
        yield 'unset' => [''];
        yield '8.2' => ['8.2'];
        yield '8.4' => ['8.4'];
        yield '8.5' => ['8.5'];
    }
}
