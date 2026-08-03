<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StrspnJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/**
 * StringStrspn length-bounded LLVM under thin AOT (#27053 / #27054; was NestedJIT #24174).
 */
final class StrspnRuntimeShrinkTest extends TestCase
{
    public function testStringStrspnUsesLengthBoundedLlvmNotNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrspn.php');
        $this->assertStringContainsString('phpc_strspn_extended', $source);
        $this->assertStringContainsString('emitExtendedBody', $source);
        $this->assertStringContainsString('__string__strlen', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('StrspnJitHelper::', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitStrspn.php');

        $lowering = (string) file_get_contents(__DIR__.'/../../ext/standard/SpnJitLowering.php');
        $this->assertStringContainsString('phpc_strspn_extended', $lowering);
        $this->assertStringContainsString('tryCompileTimeFold', $lowering);
        $this->assertStringNotContainsString('JitStrspn', $lowering);
    }

    public function testStrspnJitHelperStillDelegatesToVmStringForVm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StrspnJitHelper.php');
        $this->assertStringContainsString('VmString::strspn', $source);
        $this->assertStringContainsString('VmString::strcspn', $source);

        $this->assertSame(3, StrspnJitHelper::strspnArgv('abc123', 'abc'));
        $this->assertSame(3, VmString::strspn('abc123', 'abc'));
        $this->assertSame(3, StrspnJitHelper::strcspnArgv('abc123', '123'));
        $this->assertSame(3, VmString::strcspn('abc123', '123'));
        $this->assertSame(0, StrspnJitHelper::extendedArgvInt('123', 'abc', 0, 0, 1, 1));
        $this->assertSame(3, StrspnJitHelper::extendedArgvInt('abc123', 'abc', 0, 0, 1, 1));
    }

    public function testSpineBundleIncludesStringStrspn(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitStrspn.php', $spine);
        $this->assertStringContainsString('StrspnJitHelper.php', $spine);
        $this->assertStringContainsString('StringStrspn.php', $spine);
    }
}
