<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\HtmlspecialcharsDecodeJitHelper;
use PHPUnit\Framework\TestCase;

/** htmlspecialchars_decode() JIT routes through HtmlspecialcharsDecodeJitHelper PHP not inline LLVM (#14820). */
final class HtmlspecialcharsDecodeRuntimeShrinkTest extends TestCase
{
    public function testStringHtmlspecialcharsDecodeUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHtmlspecialcharsDecode.php');
        $this->assertStringContainsString('HtmlspecialcharsDecodeJitHelper', $source);
        $this->assertStringContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringContainsString('StringHtmlspecialcharsDecodeLlvm', $source);
        $this->assertStringNotContainsString('countLoop', $source);
        $this->assertStringNotContainsString('writeLoop', $source);
        $this->assertStringNotContainsString('quoteBothFlag', $source);
    }

    public function testHtmlspecialcharsDecodeJitHelperMirrorsVmStringSemantics(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/HtmlspecialcharsDecodeJitHelper.php');
        $this->assertStringContainsString('entityAt', $source);
        $this->assertStringContainsString('Self-contained', $source);

        $input = '&lt;a&gt;&quot;b&#039;c';
        $flags = ENT_QUOTES | ENT_HTML5;
        $expected = \PHPCompiler\ext\standard\VmString::htmlspecialchars_decode($input, $flags);
        $this->assertSame($expected, HtmlspecialcharsDecodeJitHelper::htmlspecialcharsDecodeArgv($input, $flags));
    }

    public function testSpineBundleIncludesHtmlspecialcharsDecodeJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('HtmlspecialcharsDecodeJitHelper.php', $spine);
        $this->assertStringContainsString('StringHtmlspecialcharsDecode.php', $spine);
    }

    public function testJitHtmlspecialcharsDecodeEnsuresLazyBridgeLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitHtmlspecialcharsDecode.php');
        $this->assertStringContainsString('StringHtmlspecialcharsDecode::ensureLinked', $source);
    }

    public function testContextMinimalUserStandaloneBodiesRegistersDecodeBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('StringHtmlspecialchars::ensureStandaloneBodies', $source);
        $this->assertStringContainsString('StringHtmlspecialcharsDecode::ensureStandaloneBodies', $source);
    }
}
