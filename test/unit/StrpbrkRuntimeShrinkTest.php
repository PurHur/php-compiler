<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StrpbrkJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** strpbrk() JIT routes through StrpbrkJitHelper PHP not inline LLVM (#14791). */
final class StrpbrkRuntimeShrinkTest extends TestCase
{
    public function testStringStrpbrkUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrpbrk.php');
        $this->assertStringContainsString('StrpbrkJitHelper', $source);

        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStrpbrk.php');
        $this->assertStringContainsString('StringStrpbrk::invoke', $jit);
        $this->assertStringNotContainsString("lookupFunction('strpbrk')", $jit);
        $this->assertStringNotContainsString('jitCopySlice', $jit);
    }

    public function testStrpbrkJitHelperDelegatesToVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StrpbrkJitHelper.php');
        $this->assertStringContainsString('VmString::strpbrk', $source);

        $this->assertSame(' World', StrpbrkJitHelper::strpbrkArgv('Hello World', ' '));
        $this->assertSame(' World', VmString::strpbrk('Hello World', ' '));
        $this->assertNull(StrpbrkJitHelper::strpbrkArgv('abc', 'xyz'));
    }

    public function testSpineBundleIncludesStrpbrkJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('StrpbrkJitHelper.php', $spine);
        $this->assertStringContainsString('StringStrpbrk.php', $spine);
    }
}
