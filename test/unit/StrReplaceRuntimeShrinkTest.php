<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StrReplaceJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** str_replace() subject routing + StrReplaceJitHelper NestedJIT path (#14779, #23912). */
final class StrReplaceRuntimeShrinkTest extends TestCase
{
    public function testStringStrReplaceUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrReplace.php');
        $this->assertStringContainsString('StrReplaceJitHelper', $source);

        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStrReplace.php');
        $this->assertStringContainsString('StringStrReplace::invoke', $jit);
        $this->assertStringNotContainsString('JitStringSearch::findOffsetI32', $jit);
        $this->assertStringNotContainsString('StringExplode::invoke', $jit);

        $replace = (string) file_get_contents(__DIR__.'/../../ext/standard/str_replace.php');
        $this->assertStringContainsString('JitStrReplace::replace', $replace);
        $this->assertStringContainsString('JitStrReplaceSubject', $replace);

        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStrReplaceSubject.php');
    }

    public function testStrReplaceJitHelperWalkOriginalSubject(): void
    {
        $this->assertSame('heLLo', StrReplaceJitHelper::replaceArgv('l', 'L', 'hello'));
        $this->assertSame(0, StrReplaceJitHelper::takeLastCount());
        $this->assertSame('hell0 w0rld', StrReplaceJitHelper::replaceArgv('o', '0', 'hello world'));
        $this->assertSame('hello', StrReplaceJitHelper::replaceArgv('', 'X', 'hello'));

        $ssotCount = 0;
        $this->assertSame('heLLo', VmString::strReplace('l', 'L', 'hello', $ssotCount));
        $this->assertSame(2, $ssotCount);

        $expectedCount = 0;
        $expected = VmString::strIreplace('l', 'x', 'Hello', $expectedCount);
        $this->assertSame($expected, StrReplaceJitHelper::ireplaceArgv('l', 'x', 'Hello'));
        $this->assertSame($expectedCount, StrReplaceJitHelper::takeLastCount());
    }

    public function testSpineBundleIncludesStrReplaceJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('StrReplaceJitHelper.php', $spine);
        $this->assertStringContainsString('StringStrReplace.php', $spine);
    }
}
