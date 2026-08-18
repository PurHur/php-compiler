<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;
use PHPCompiler\ext\standard\HtmlspecialcharsJitHelper;
use PHPCompiler\ext\standard\VmString;

/**
 * HtmlspecialcharsJitHelper stays NestedJIT-safe and matches VmString subset (#20487, #25345, #27290).
 */
final class HtmlspecialcharsRuntimeShrinkTest extends TestCase
{
    public function testHtmlspecialcharsJitHelperSourceIsSelfContained(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/HtmlspecialcharsJitHelper.php');
        $this->assertStringContainsString('&amp;', $source);
        $this->assertStringContainsString('escapeFrom', $source);
        $this->assertStringContainsString('htmlspecialcharsEx', $source);
        $this->assertStringContainsString('isset($string[$i])', $source);
        $this->assertStringNotContainsString('return VmString::', $source);
        $this->assertStringNotContainsString('strlen(', $source);
        $this->assertStringNotContainsString('substr(', $source);
        $this->assertDoesNotMatchRegularExpression('/\$out\s*\.=/', $source);
    }

    public function testHtmlspecialcharsJitHelperMatchesVmStringSubset(): void
    {
        $flags = ENT_QUOTES | ENT_SUBSTITUTE;
        foreach (['MiniWebApp', 'a&b<c>"\'', 'Home', ''] as $s) {
            $this->assertSame(
                VmString::htmlspecialchars($s, $flags, 'UTF-8', true),
                HtmlspecialcharsJitHelper::htmlspecialchars($s, $flags),
                'mismatch for '.var_export($s, true)
            );
        }
    }

    public function testHtmlspecialcharsJitHelperEntIgnoreMatchesVmString(): void
    {
        $flags = ENT_QUOTES | ENT_IGNORE;
        foreach (["\xC3\x28", "a\xC3\x28b", "\xC3\xA9", 'ok'] as $s) {
            $this->assertSame(
                VmString::htmlspecialchars($s, $flags, 'UTF-8', true),
                HtmlspecialcharsJitHelper::htmlspecialchars($s, $flags),
                'ENT_IGNORE mismatch for '.bin2hex($s)
            );
        }
        $this->assertSame('(', HtmlspecialcharsJitHelper::htmlspecialchars("\xC3\x28", $flags));
        $this->assertSame('a(b', HtmlspecialcharsJitHelper::htmlspecialchars("a\xC3\x28b", $flags));
    }

    public function testHtmlspecialcharsExDoubleEncodeFalseMatchesVmString(): void
    {
        $flags = ENT_QUOTES;
        foreach (['&amp;', '&lt;', '&', 'Tom & Jerry', '&#38;', '&foo;', "<>&'\""] as $s) {
            $this->assertSame(
                VmString::htmlspecialchars($s, $flags, 'UTF-8', false),
                HtmlspecialcharsJitHelper::htmlspecialcharsEx($s, $flags, 0),
                'double_encode=false mismatch for '.var_export($s, true)
            );
            $this->assertSame(
                VmString::htmlspecialchars($s, $flags, 'UTF-8', true),
                HtmlspecialcharsJitHelper::htmlspecialcharsEx($s, $flags, 1),
                'double_encode=true mismatch for '.var_export($s, true)
            );
        }
    }
}
