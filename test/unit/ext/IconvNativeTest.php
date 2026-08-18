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

    /** U+20AC and other non-Latin-1 codepoints — glibc //TRANSLIT (#32103). */
    public function testTranslitSuffixMapsUnicodeSymbolsToAscii(): void
    {
        $this->assertSame('aEURb', CharsetEngine::convert('UTF-8', 'ASCII//TRANSLIT', 'a€b'));
        $this->assertSame('aEURb', VmIconv::iconv('UTF-8', 'ASCII//TRANSLIT', 'a€b'));
        $this->assertSame('ab', CharsetEngine::convert('UTF-8', 'ASCII//IGNORE', 'a€b'));
        $this->assertSame('JPY100', CharsetEngine::convert('UTF-8', 'ASCII//TRANSLIT', '¥100'));
    }

    public function testUtf16leRoundTrip(): void
    {
        $le = CharsetEngine::convert('UTF-8', 'UTF-16LE', 'a');
        $this->assertSame('6100', bin2hex((string) $le));
        $this->assertSame('a', CharsetEngine::convert('UTF-16LE', 'UTF-8', (string) $le));
        $this->assertSame('a', VmIconv::iconv('UTF-16LE', 'UTF-8', (string) $le));
    }

    public function testUtf8IgnoreSuffixStripsInvalidBytesOnSameEncoding(): void
    {
        $input = "a\xc0b";
        $this->assertSame('ab', CharsetEngine::convert('UTF-8', 'UTF-8//IGNORE', $input));
        $this->assertSame('ab', VmIconv::iconv('UTF-8', 'UTF-8//IGNORE', $input));
    }

    /** Same-charset illegal sequence must fail (php-src iconv.c / #25167). */
    public function testSameCharsetIllegalUtf8ReturnsFalse(): void
    {
        $input = "a\x80b";
        $this->assertFalse(CharsetEngine::convert('UTF-8', 'UTF-8', $input));
        $this->assertSame(CharsetEngine::ERROR_ILLEGAL, CharsetEngine::lastError());
        $this->assertFalse(VmIconv::iconv('UTF-8', 'UTF-8', $input));
        $this->assertSame('ab', CharsetEngine::convert('UTF-8', 'UTF-8//IGNORE', $input));
    }

    public function testSameCharsetIllegalAsciiReturnsFalse(): void
    {
        $this->assertFalse(CharsetEngine::convert('ASCII', 'ASCII', "\x80"));
        $this->assertSame(CharsetEngine::ERROR_ILLEGAL, CharsetEngine::lastError());
        $this->assertSame("\x80", CharsetEngine::convert('ISO-8859-1', 'ISO-8859-1', "\x80"));
    }

    public function testIconvStringHelpersLatin1(): void
    {
        $iso = "\xE9\xE8\xE7";
        $this->assertSame(3, VmIconv::iconvStrlen($iso, 'ISO-8859-1'));
        $this->assertSame("\xE8\xE7", VmIconv::iconvSubstr($iso, 1, 2, 'ISO-8859-1'));
        $this->assertSame(1, VmIconv::iconvStrpos($iso, "\xE8", 1, 'ISO-8859-1'));
        $this->assertSame(0, VmIconv::iconvStrrpos($iso, "\xE9", 'ISO-8859-1'));
    }

    public function testIconvStrlenRejectsInvalidUtf8(): void
    {
        $this->assertFalse(VmIconv::iconvStrlen("\xFF", 'UTF-8'));
    }

    public function testIconvNullEncodingResolvesToDefaultCharset(): void
    {
        $this->assertSame('hi', VmIconv::iconv('', 'UTF-8', 'hi'));
        $this->assertSame('hi', VmIconv::iconv('UTF-8', '', 'hi'));
    }

    public function testIconvImplVersionIdentityIsHonestCharsetEngine(): void
    {
        $registered = \PHPCompiler\ext\iconv\IconvConstants::registeredConstants();
        $this->assertSame('php-compiler', $registered['ICONV_IMPL']);
        $this->assertSame('1.0', $registered['ICONV_VERSION']);
        $this->assertSame(1, $registered['ICONV_MIME_DECODE_STRICT']);
    }
}
