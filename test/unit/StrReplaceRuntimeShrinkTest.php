<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StrReplaceJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** str_replace()/str_ireplace() JIT routes through StrReplaceJitHelper PHP not inline LLVM (#14779). */
final class StrReplaceRuntimeShrinkTest extends TestCase
{
    public function testStringStrReplaceUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrReplace.php');
        $this->assertStringContainsString('StrReplaceJitHelper', $source);

        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStrReplace.php');
        $this->assertStringContainsString('StringStrReplace::invoke', $jit);
        $this->assertStringNotContainsString('JitStringSearch::findOffsetI32', $jit);
        $this->assertStringNotContainsString('string_trim::jitCopySlice', $jit);

        $replace = (string) file_get_contents(__DIR__.'/../../ext/standard/str_replace.php');
        $this->assertStringContainsString('JitStrReplace::replace', $replace);

        $ireplace = (string) file_get_contents(__DIR__.'/../../ext/standard/str_ireplace.php');
        $this->assertStringContainsString('JitStrIreplace::replace', $ireplace);
    }

    public function testStrReplaceJitHelperDelegatesToVmString(): void
    {
        $this->assertSame('heLLo', StrReplaceJitHelper::replaceArgv('l', 'L', 'hello'));
        $this->assertSame(2, StrReplaceJitHelper::takeLastCount());

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
        $this->assertStringContainsString('JitStrReplaceSearchReplaceGuard.php', $spine);
    }
}
