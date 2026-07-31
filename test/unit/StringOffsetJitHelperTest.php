<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\StringOffsetJitHelper;
use PHPUnit\Framework\TestCase;

/** StringOffsetJitHelper SSOT for JIT string offset semantics (#10245). */
final class StringOffsetJitHelperTest extends TestCase
{
    public function testNormalizeByteIndexPositive(): void
    {
        $this->assertSame(1, StringOffsetJitHelper::normalizeByteIndex(1, 3));
    }

    public function testNormalizeByteIndexNegative(): void
    {
        $this->assertSame(2, StringOffsetJitHelper::normalizeByteIndex(-1, 3));
    }

    public function testIncDecErrorMessage(): void
    {
        $this->assertSame(
            'Cannot increment/decrement string offsets',
            StringOffsetJitHelper::incDecErrorMessage()
        );
    }

    public function testAssignOpErrorMessage(): void
    {
        $this->assertSame(
            'Cannot use assign-op operators with string offsets',
            StringOffsetJitHelper::assignOpErrorMessage()
        );
    }

    public function testObjectToStringErrorMessage(): void
    {
        $this->assertSame(
            'Object of class D could not be converted to string',
            StringOffsetJitHelper::objectToStringErrorMessage('D')
        );
    }

    public function testByteFromLongUsesDecimalFirstByte(): void
    {
        // Zend convert_to_string then first byte — not trunc-to-u8 (#25778).
        $this->assertSame(\ord('6'), StringOffsetJitHelper::byteFromLong(65));
        $this->assertSame(\ord('2'), StringOffsetJitHelper::byteFromLong(257));
        $this->assertSame(\ord('0'), StringOffsetJitHelper::byteFromLong(0));
        $this->assertSame(\ord('-'), StringOffsetJitHelper::byteFromLong(-1));
        $this->assertTrue(StringOffsetJitHelper::longNeedsFirstByteWarning(65));
        $this->assertFalse(StringOffsetJitHelper::longNeedsFirstByteWarning(5));
        $this->assertTrue(StringOffsetJitHelper::longNeedsFirstByteWarning(-1));
    }

    public function testByteFromStringFirstChar(): void
    {
        $this->assertSame(122, StringOffsetJitHelper::byteFromStringFirstChar('z'));
    }

    public function testReadOffsetPositiveAndOor(): void
    {
        $this->assertSame('A', StringOffsetJitHelper::readOffset('AOT', 0));
        $this->assertSame('T', StringOffsetJitHelper::readOffset('AOT', -1));
        @$empty = StringOffsetJitHelper::readOffset('AOT', 99);
        $this->assertSame('', $empty);
    }
}
