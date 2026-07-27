<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StrReplaceJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** str_replace() subject routing + inline LLVM JitStrReplace (#14779, #23912). */
final class StrReplaceRuntimeShrinkTest extends TestCase
{
    public function testJitStrReplaceUsesInlineLlvmForAotScalarPath(): void
    {
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStrReplace.php');
        // #23912: NestedJIT helper is wrong under user-script AOT — scalar path is LLVM again.
        $this->assertStringContainsString('JitStringSearch::findOffsetI32', $jit);
        $this->assertStringContainsString('string_trim::jitCopySlice', $jit);
        $this->assertStringContainsString('BasicBlockHelper::entryAlloca', $jit);
        $this->assertStringNotContainsString('StringStrReplace::invoke', $jit);

        $replace = (string) file_get_contents(__DIR__.'/../../ext/standard/str_replace.php');
        $this->assertStringContainsString('JitStrReplace::replace', $replace);
        $this->assertStringContainsString('JitStrReplaceSubject', $replace);

        $ireplace = (string) file_get_contents(__DIR__.'/../../ext/standard/str_ireplace.php');
        $this->assertStringContainsString('JitStrIreplace::replace', $ireplace);
        $this->assertStringContainsString('JitStrReplaceSubject', $ireplace);

        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStrReplaceSubject.php');
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
