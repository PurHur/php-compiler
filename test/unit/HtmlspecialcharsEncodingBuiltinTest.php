<?php

declare(strict_types=1);

namespace Test\Unit;

use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** VM htmlspecialchars() encoding + double_encode parity (issue #3784). */
final class HtmlspecialcharsEncodingBuiltinTest extends TestCase
{
    public function testIso88591EncodingDelegatesToHost(): void
    {
        $expected = \htmlspecialchars("\xE4", ENT_QUOTES, 'ISO-8859-1');
        self::assertSame($expected, VmString::htmlspecialchars("\xE4", ENT_QUOTES, 'ISO-8859-1'));
    }

    public function testWindows1252EncodingDelegatesToHost(): void
    {
        $expected = \htmlspecialchars('<x>', ENT_QUOTES, 'Windows-1252');
        self::assertSame($expected, VmString::htmlspecialchars('<x>', ENT_QUOTES, 'Windows-1252'));
    }

    public function testUtf8DoubleEncodeFalsePreservesEntities(): void
    {
        self::assertSame('&amp;', VmString::htmlspecialchars('&amp;', ENT_QUOTES, 'UTF-8', false));
    }
}
