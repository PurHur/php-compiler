<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StrpbrkJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** strpbrk() AOT uses length-bounded LLVM in StringStrpbrk (#27055); helper kept as SSOT peer. */
final class StrpbrkRuntimeShrinkTest extends TestCase
{
    public function testStringStrpbrkEmitsLengthBoundedLlvmNotNestedJitBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrpbrk.php');
        $this->assertStringContainsString('phpc_strpbrk_scan', $source);
        $this->assertStringContainsString('__string__strlen', $source);
        $this->assertStringNotContainsString('JitVmHelperLink', $source);
        $this->assertStringNotContainsString('StrpbrkJitHelper::', $source);

        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStrpbrk.php');
        $this->assertStringContainsString('StringStrpbrk::invoke', $jit);
        $this->assertStringNotContainsString("lookupFunction('strpbrk')", $jit);
    }

    public function testStrpbrkJitHelperMatchesVmString(): void
    {
        $this->assertSame(' World', StrpbrkJitHelper::strpbrkArgv('Hello World', ' '));
        $this->assertSame('ello', StrpbrkJitHelper::strpbrkArgv('hello', 'aeiou'));
        $this->assertSame(' World', VmString::strpbrk('Hello World', ' '));
        $this->assertNull(StrpbrkJitHelper::strpbrkArgv('abc', 'xyz'));
        $this->assertFalse(VmString::strpbrk('abc', 'xyz'));
    }

    public function testSpineBundleIncludesStringStrpbrk(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('StringStrpbrk.php', $spine);
    }
}
