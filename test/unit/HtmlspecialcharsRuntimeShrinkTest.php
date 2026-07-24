<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** htmlspecialchars() JIT routes through HtmlspecialcharsJitHelper PHP for embed + user-script AOT (#9445, #20487). */
final class HtmlspecialcharsRuntimeShrinkTest extends TestCase
{
    public function testStringHtmlspecialcharsUsesJitHelperNotKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHtmlspecialchars.php');
        $this->assertStringContainsString('HtmlspecialcharsJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('htmlspecialchars_bridge_entry', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('JitHtmlspecialcharsKernel', $source);
        $this->assertStringNotContainsString('htmlspecialchars_kernel_entry', $source);
        $this->assertStringNotContainsString('StreamIoRuntime::shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('StringHtmlspecialcharsUserScriptLlvm', $source);
        $this->assertStringNotContainsString('StringHtmlspecialcharsStandaloneLlvm', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitHtmlspecialcharsKernel.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringHtmlspecialcharsUserScriptLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringHtmlspecialcharsStandaloneLlvm.php');
    }

    public function testHtmlspecialcharsJitHelperIsSelfContained(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/HtmlspecialcharsJitHelper.php');
        $this->assertStringContainsString('&amp;', $source);
        $this->assertStringContainsString('isset($string[$len])', $source);
        $this->assertStringNotContainsString('return VmString::', $source);
        $this->assertStringNotContainsString('strlen(', $source);
        $this->assertStringNotContainsString('substr(', $source);
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

        // ASCII MiniWebApp / title path (#22845) — UTF-8 invalid handling deferred (NestedJIT).
        $this->assertSame(
            'MiniWebApp',
            \PHPCompiler\ext\standard\HtmlspecialcharsJitHelper::htmlspecialchars('MiniWebApp', ENT_QUOTES)
        );
    }

    public function testSpineBundleIncludesHtmlspecialcharsPhpPath(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('HtmlspecialcharsJitHelper.php', $spine);
        $this->assertStringContainsString('StringHtmlspecialchars.php', $spine);
        $this->assertStringNotContainsString('JitHtmlspecialcharsKernel.php', $spine);
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
