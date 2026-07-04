<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\intl\VmGrapheme;
use PHPUnit\Framework\TestCase;

/** @covers issue #9793 */
final class VmGraphemeStrimwidthTest extends TestCase
{
    public function testStrimwidthJapaneseWithEllipsis(): void
    {
        $result = VmGrapheme::strimwidth('こんにちは', 0, 3, '...');
        $this->assertIsString($result);
        $this->assertStringEndsWith('...', $result);
        $this->assertLessThan(\strlen('こんにちは'), \strlen($result));
    }

    public function testStrimwidthAsciiNoTrim(): void
    {
        $this->assertSame('hello', VmGrapheme::strimwidth('hello', 0, 10));
    }

    public function testStrimwidthInvalidUtf8ReturnsFalse(): void
    {
        $this->assertFalse(VmGrapheme::strimwidth("\xFF", 0, 1));
    }
}
