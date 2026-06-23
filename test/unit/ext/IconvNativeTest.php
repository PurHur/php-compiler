<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\Ext;

use PHPCompiler\ext\iconv\CharsetEngine;
use PHPCompiler\ext\iconv\VmIconv;
use PHPUnit\Framework\TestCase;

/** Native iconv charset engine (#6251). */
final class IconvNativeTest extends TestCase
{
    public function testLatin1ToUtf8MatchesZendShape(): void
    {
        $bytes = "\xE9";
        $this->assertSame(
            \function_exists('iconv') ? \iconv('ISO-8859-1', 'UTF-8', $bytes) : "\xC3\xA9",
            CharsetEngine::convert('ISO-8859-1', 'UTF-8', $bytes)
        );
        $this->assertSame(
            CharsetEngine::convert('ISO-8859-1', 'UTF-8', $bytes),
            VmIconv::iconv('ISO-8859-1', 'UTF-8', $bytes)
        );
    }

    public function testEncodingAliasesNormalize(): void
    {
        $bytes = 'A';
        $this->assertSame('A', CharsetEngine::convert('latin1', 'UTF-8', $bytes));
        $this->assertSame('A', CharsetEngine::convert('ISO8859-1', 'UTF-8', $bytes));
        $this->assertSame('A', CharsetEngine::convert('UTF8', 'UTF-8', $bytes));
    }

    public function testUtf8RoundTripLatin1(): void
    {
        $utf8 = "\xC3\xA9";
        $latin1 = CharsetEngine::convert('UTF-8', 'ISO-8859-1', $utf8);
        $this->assertSame("\xE9", $latin1);
        $this->assertSame($utf8, CharsetEngine::convert('ISO-8859-1', 'UTF-8', (string) $latin1));
    }

    public function testUnknownEncodingReturnsFalse(): void
    {
        $this->assertFalse(VmIconv::iconv('KOI8-R', 'UTF-8', 'x'));
        $this->assertFalse(VmIconv::iconv('UTF-8', 'INVALID//IGNORE', 'hello'));
    }

    public function testIgnoreSuffixStripsInvalidAsciiBytes(): void
    {
        $this->assertSame('A', CharsetEngine::convert('ASCII//IGNORE', 'UTF-8', "A\xFF"));
    }

    public function testTranslitSuffixMapsLatin1AccentsToAscii(): void
    {
        $this->assertSame('cafe', CharsetEngine::convert('UTF-8', 'ASCII//TRANSLIT', 'café'));
        $this->assertSame(
            'cafe',
            VmIconv::iconv('UTF-8', 'ASCII//TRANSLIT', 'café')
        );
        $this->assertSame('caf', CharsetEngine::convert('UTF-8', 'ASCII//IGNORE', "caf\xC3\xA9"));
    }

    public function testUtf16leRoundTrip(): void
    {
        $le = CharsetEngine::convert('UTF-8', 'UTF-16LE', 'a');
        $this->assertSame('6100', bin2hex((string) $le));
        $this->assertSame('a', CharsetEngine::convert('UTF-16LE', 'UTF-8', (string) $le));
        $this->assertSame('a', VmIconv::iconv('UTF-16LE', 'UTF-8', (string) $le));
    }
}
