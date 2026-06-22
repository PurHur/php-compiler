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

    public function testByteFromLongTruncates(): void
    {
        $this->assertSame(65, StringOffsetJitHelper::byteFromLong(65));
        $this->assertSame(1, StringOffsetJitHelper::byteFromLong(257));
    }

    public function testByteFromStringFirstChar(): void
    {
        $this->assertSame(122, StringOffsetJitHelper::byteFromStringFirstChar('z'));
    }
}
