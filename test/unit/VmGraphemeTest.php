<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\intl\VmGrapheme;
use PHPUnit\Framework\TestCase;

/** Issue #7888: VmGrapheme must not delegate to host \\grapheme_*() (bootstrap self-host). */
final class VmGraphemeTest extends TestCase
{
    public function testVmGraphemeDoesNotReferenceHostGraphemeFunctions(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/intl/VmGrapheme.php');
        $this->assertStringNotContainsString('function_exists(\'grapheme_str_contains\')', $source);
        $this->assertStringNotContainsString('function_exists(\'grapheme_strpos\')', $source);
        $this->assertStringNotContainsString('function_exists(\'grapheme_levenshtein\')', $source);
        $this->assertStringNotContainsString('function_exists(\'grapheme_str_split\')', $source);
        $this->assertStringNotContainsString('function_exists(\'grapheme_extract\')', $source);
        $this->assertStringNotContainsString('\\grapheme_str_contains(', $source);
        $this->assertStringNotContainsString('\\grapheme_strpos(', $source);
        $this->assertStringNotContainsString('\\grapheme_levenshtein(', $source);
        $this->assertStringNotContainsString('\\grapheme_str_split(', $source);
        $this->assertStringNotContainsString('\\grapheme_extract(', $source);
        $this->assertStringContainsString('strContainsUtf8', $source);
    }

    public function testStrContainsBasicPatterns(): void
    {
        $this->assertTrue(VmGrapheme::strContains('hello', 'ell'));
        $this->assertFalse(VmGrapheme::strContains('hello', 'z'));
        $this->assertTrue(VmGrapheme::strContains('', ''));
        $this->assertTrue(VmGrapheme::strContains('café', 'é'));
    }

    public function testStrContainsNfdHaystackPrecomposedNeedle(): void
    {
        $nfd = "cafe\u{0301}";
        $nfc = "caf\u{00E9}";
        $this->assertTrue(VmGrapheme::strContains($nfd, "\u{00E9}"));
        $this->assertTrue(VmGrapheme::strContains($nfc, "e\u{0301}"));
        $this->assertSame(4, VmGrapheme::strlen($nfd));
        $this->assertSame(4, VmGrapheme::strlen($nfc));
    }

    public function testLevenshteinGraphemeClusters(): void
    {
        $nfc = "caf\u{00E9}";
        $nfd = "caf\u{0065}\u{0301}";
        $this->assertSame(0, VmGrapheme::levenshtein($nfc, $nfd));
        $this->assertSame(3, VmGrapheme::levenshtein('kitten', 'sitting'));
        $this->assertSame(0, VmGrapheme::levenshtein('', ''));
        $this->assertSame(4, VmGrapheme::levenshtein('ab', 'a', 2, 3, 4));
        $this->assertSame(4, VmGrapheme::levenshtein('', 'ab', 2, 3, 4));
        $this->assertSame(8, VmGrapheme::levenshtein('ab', '', 2, 3, 4));
    }

    public function testStrSplitGraphemeClusters(): void
    {
        $one = VmGrapheme::strSplit("e\xCC\x81");
        $this->assertIsArray($one);
        $this->assertCount(1, $one);
        $this->assertSame(['a', 'b', 'c'], VmGrapheme::strSplit('abc'));
        $this->assertSame(['ab', 'cd', 'ef'], VmGrapheme::strSplit('abcdef', 2));
        $this->assertSame([], VmGrapheme::strSplit(''));
    }

    public function testExtractGraphemeClusters(): void
    {
        $s = "a\xCC\x81b";
        $this->assertSame("a\xCC\x81", VmGrapheme::extract($s, 1));
        $this->assertSame("a\xCC\x81b", VmGrapheme::extract($s, 2));
        $this->assertSame('bc', VmGrapheme::extract('abc', 2, VmGrapheme::EXTR_COUNT, 1));
        $next = 0;
        $this->assertSame('ab', VmGrapheme::extract('abcdef', 2, VmGrapheme::EXTR_COUNT, 0, $next));
        $this->assertSame(2, $next);
        $this->assertSame('', VmGrapheme::extract('abc', 0));
        $this->assertFalse(VmGrapheme::extract('abc', 1, 99));
    }

    public function testSubstrGraphemeClusters(): void
    {
        $s = "a\xCC\x81b";
        $this->assertSame(2, VmGrapheme::strlen($s));
        $this->assertSame("a\xCC\x81", VmGrapheme::substr($s, 0, 1));
        $this->assertSame('b', VmGrapheme::substr($s, 1));
        $this->assertSame('', VmGrapheme::substr($s, 5));
        $this->assertSame('bc', VmGrapheme::substr('abc', 1, 2));
        $this->assertFalse(VmGrapheme::substr("\xFF", 0, 1));
    }

    public function testStrposGraphemeClusters(): void
    {
        $s = "a\xCC\x81b";
        $this->assertSame(1, VmGrapheme::strpos($s, 'b'));
        $this->assertFalse(VmGrapheme::strpos($s, 'z'));
        // Empty needle → UTF-16 code-unit offset (php-src grapheme_strpos_utf16; #29495)
        $this->assertSame(0, VmGrapheme::strpos($s, ''));
        $this->assertSame(2, VmGrapheme::strpos($s, '', 1));
        $this->assertSame(3, VmGrapheme::strrpos($s, ''));
        $this->assertSame(0, VmGrapheme::strpos('ab', ''));
        $this->assertSame(2, VmGrapheme::strrpos('ab', ''));
        $this->assertSame(1, VmGrapheme::strpos('ababa', 'b', 1));
    }
}
