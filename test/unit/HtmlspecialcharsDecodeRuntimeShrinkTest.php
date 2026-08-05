<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\HtmlspecialcharsDecodeJitHelper;
use PHPUnit\Framework\TestCase;

/** htmlspecialchars_decode() JIT routes through HtmlspecialcharsDecodeJitHelper PHP for embed + user-script AOT (#14820, #18954). */
final class HtmlspecialcharsDecodeRuntimeShrinkTest extends TestCase
{
    public function testStringHtmlspecialcharsDecodeUsesJitHelperNotLlvmMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHtmlspecialcharsDecode.php');
        $this->assertStringContainsString('HtmlspecialcharsDecodeJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('StringHtmlspecialcharsDecodeLlvm', $source);
        $this->assertStringNotContainsString('countLoop', $source);
        $this->assertStringNotContainsString('writeLoop', $source);
        $this->assertStringNotContainsString('quoteBothFlag', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringHtmlspecialcharsDecodeLlvm.php');
    }

    public function testHtmlspecialcharsDecodeJitHelperMirrorsVmStringSemantics(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/HtmlspecialcharsDecodeJitHelper.php');
        $this->assertStringContainsString('decodeFrom', $source);
        $this->assertStringContainsString('entityMatch', $source);
        $this->assertStringContainsString('Self-contained', $source);
        $this->assertDoesNotMatchRegularExpression('/\$len\s*=\s*\\\\strlen\s*\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/while\s*\(\s*\$i\s*</', $source);

        $input = '&lt;a&gt;&quot;b&#039;c';
        $flags = ENT_QUOTES | ENT_HTML5;
        $expected = \PHPCompiler\ext\standard\VmString::htmlspecialchars_decode($input, $flags);
        $this->assertSame($expected, HtmlspecialcharsDecodeJitHelper::htmlspecialcharsDecodeArgv($input, $flags));
    }

    public function testHelperRuntimeCacheForcesDecodeInlineForUserScriptAot(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/AOT/HelperRuntimeCache.php');
        $this->assertStringContainsString(
            'htmlspecialcharsdecodejithelper::htmlspecialcharsdecodeargv',
            $source
        );
        $this->assertStringContainsString('#27050', $source);
    }

    public function testSpineBundleOmitsDeletedHtmlspecialcharsDecodeLlvm(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('HtmlspecialcharsDecodeJitHelper.php', $spine);
        $this->assertStringContainsString('StringHtmlspecialcharsDecode.php', $spine);
        $this->assertStringNotContainsString('StringHtmlspecialcharsDecodeLlvm.php', $spine);
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
