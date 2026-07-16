<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** htmlspecialchars() JIT: PHP helper embed + ext kernel for user-script AOT (#9445, #18967, #19389). */
final class HtmlspecialcharsRuntimeShrinkTest extends TestCase
{
    public function testStringHtmlspecialcharsUsesJitHelperAndExtKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHtmlspecialchars.php');
        $this->assertStringContainsString('HtmlspecialcharsJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('StreamIoRuntime::shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertStringContainsString('JitHtmlspecialcharsKernel', $source);
        $this->assertStringContainsString('htmlspecialchars_kernel_entry', $source);
        $this->assertStringNotContainsString('StringHtmlspecialcharsUserScriptLlvm', $source);
        $this->assertStringNotContainsString('StringHtmlspecialcharsStandaloneLlvm', $source);
        $this->assertStringNotContainsString('htmlspecialchars_count_head', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringHtmlspecialcharsUserScriptLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringHtmlspecialcharsStandaloneLlvm.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitHtmlspecialcharsKernel.php');
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

        $compat = ENT_COMPAT;
        $this->assertSame(
            \PHPCompiler\ext\standard\VmString::htmlspecialchars($input, $compat),
            \PHPCompiler\ext\standard\HtmlspecialcharsJitHelper::htmlspecialchars($input, $compat)
        );
    }

    public function testSpineBundleIncludesHtmlspecialcharsKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('HtmlspecialcharsJitHelper.php', $spine);
        $this->assertStringContainsString('StringHtmlspecialchars.php', $spine);
        $this->assertStringContainsString('JitHtmlspecialcharsKernel.php', $spine);
        $this->assertStringNotContainsString('StringHtmlspecialcharsUserScriptLlvm.php', $spine);
        $this->assertStringNotContainsString('StringHtmlspecialcharsStandaloneLlvm.php', $spine);
    }

    public function testContextMinimalUserStandaloneBodiesRegistersEncodeBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('StringHtmlspecialchars::ensureStandaloneBodies', $source);
        $this->assertStringContainsString('StringHtmlspecialcharsDecode::ensureStandaloneBodies', $source);
    }
}
