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
        $this->assertStringNotContainsString('\\grapheme_str_contains(', $source);
        $this->assertStringNotContainsString('\\grapheme_strpos(', $source);
        $this->assertStringNotContainsString('\\grapheme_levenshtein(', $source);
        $this->assertStringContainsString('strContainsUtf8', $source);
    }

    public function testStrContainsBasicPatterns(): void
    {
        $this->assertTrue(VmGrapheme::strContains('hello', 'ell'));
        $this->assertFalse(VmGrapheme::strContains('hello', 'z'));
        $this->assertTrue(VmGrapheme::strContains('', ''));
        $this->assertTrue(VmGrapheme::strContains('café', 'é'));
    }

    public function testLevenshteinGraphemeClusters(): void
    {
        $nfc = "caf\u{00E9}";
        $nfd = "caf\u{0065}\u{0301}";
        $this->assertSame(0, VmGrapheme::levenshtein($nfc, $nfd));
        $this->assertSame(3, VmGrapheme::levenshtein('kitten', 'sitting'));
        $this->assertSame(0, VmGrapheme::levenshtein('', ''));
    }
}
