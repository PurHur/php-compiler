<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** StringHtmlspecialchars JIT path uses HtmlspecialcharsJitHelper PHP, not inline LLVM (#9445). */
final class HtmlspecialcharsRuntimeShrinkTest extends TestCase
{
    public function testStringHtmlspecialcharsUsesJitHelperForJitPath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHtmlspecialchars.php');
        $this->assertStringContainsString('HtmlspecialcharsJitHelper', $source);
        $this->assertStringNotContainsString('htmlspecialchars_count_head', $source);
        $this->assertStringNotContainsString('StringHtmlspecialcharsStandaloneLlvm', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringHtmlspecialcharsStandaloneLlvm.php');
    }

    public function testHtmlspecialcharsJitHelperIsSelfContained(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/HtmlspecialcharsJitHelper.php');
        $this->assertStringContainsString('&amp;', $source);
        $this->assertStringNotContainsString('return VmString::', $source);
    }

    public function testHtmlspecialcharsJitHelperMatchesVmStringSubset(): void
    {
        $input = '<a&b>"\'';
        $flags = ENT_QUOTES | ENT_HTML5;
        $expected = \PHPCompiler\ext\standard\VmString::htmlspecialchars($input, $flags);
        $actual = \PHPCompiler\ext\standard\HtmlspecialcharsJitHelper::htmlspecialchars($input, $flags);
        $this->assertSame($expected, $actual);
    }

    public function testSpineBundleIncludesHtmlspecialcharsJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('HtmlspecialcharsJitHelper.php', $spine);
        $this->assertStringNotContainsString('StringHtmlspecialcharsStandaloneLlvm.php', $spine);
    }
}
