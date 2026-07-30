<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;
use PHPCompiler\ext\standard\HtmlspecialcharsJitHelper;
use PHPCompiler\ext\standard\VmString;

/**
 * HtmlspecialcharsJitHelper stays NestedJIT-safe and matches VmString subset (#20487, #25345).
 */
final class HtmlspecialcharsRuntimeShrinkTest extends TestCase
{
    public function testHtmlspecialcharsJitHelperSourceIsSelfContained(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/HtmlspecialcharsJitHelper.php');
        $this->assertStringContainsString('&amp;', $source);
        $this->assertStringContainsString('escapeFrom', $source);
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
}
