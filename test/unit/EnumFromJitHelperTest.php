<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\EnumFromJitHelper;
use PHPUnit\Framework\TestCase;

/** EnumFromJitHelper SSOT for BackedEnum::from()/tryFrom() JIT (#10273). */
final class EnumFromJitHelperTest extends TestCase
{
    public function testMatchStringBackingPacked(): void
    {
        $packed = "red\0blue";
        $this->assertSame(0, EnumFromJitHelper::matchStringBackingPacked('red', $packed, 2));
        $this->assertSame(1, EnumFromJitHelper::matchStringBackingPacked('blue', $packed, 2));
        $this->assertSame(-1, EnumFromJitHelper::matchStringBackingPacked('green', $packed, 2));
    }

    public function testMatchIntBackingCsv(): void
    {
        $this->assertSame(0, EnumFromJitHelper::matchIntBackingCsv(1, '1,9'));
        $this->assertSame(1, EnumFromJitHelper::matchIntBackingCsv(9, '1,9'));
        $this->assertSame(-1, EnumFromJitHelper::matchIntBackingCsv(2, '1,9'));
    }

    public function testFormatValueErrors(): void
    {
        $this->assertSame(
            '"missing" is not a valid backing value for enum Color',
            EnumFromJitHelper::formatStringValueError('missing', 'Color')
        );
        $this->assertSame(
            '99 is not a valid backing value for enum Level',
            EnumFromJitHelper::formatIntValueError(99, 'Level')
        );
    }

    public function testStringBackingCoercion(): void
    {
        $this->assertSame('1', EnumFromJitHelper::stringBackingFromBool(true));
        $this->assertSame('0', EnumFromJitHelper::stringBackingFromBool(false));
        $this->assertSame('', EnumFromJitHelper::stringBackingFromNull());
        $this->assertSame('42', EnumFromJitHelper::stringBackingFromLong(42));
    }

    public function testIntBackingFromStringRejectsBadNumeric(): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('Level::from(): Argument #1 ($value) must be of type int, string given');
        EnumFromJitHelper::intBackingFromString('Level', '1abc');
    }
}
