<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\OpendirJitHelper;
use PHPUnit\Framework\TestCase;

/** opendir() JIT routes through OpendirJitHelper PHP not inline LLVM in JitOpendir (#15891). */
final class OpendirRuntimeShrinkTest extends TestCase
{
    public function testJitOpendirDelegatesToStringOpendirBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitOpendir.php');
        $this->assertStringContainsString('StringOpendir::invoke', $source);
        $this->assertStringNotContainsString('BasicBlockHelper', $source);
        $this->assertStringNotContainsString('__compiler_opendir', $source);
        $this->assertLessThan(25, \substr_count($source, "\n") + 1);
    }

    public function testStringOpendirUsesJitHelperNotWarningLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringOpendir.php');
        $this->assertStringContainsString('OpendirJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink', $source);
        $this->assertStringNotContainsString('JitBuiltinWarning', $source);
        $this->assertStringNotContainsString('StringTriggerErrorJit', $source);
    }

    public function testOpendirJitHelperEmptyPathReturnsFailureWithoutWarning(): void
    {
        $this->assertSame(-1, OpendirJitHelper::invokeArgv(''));
    }

    public function testOpendirJitHelperOpensCurrentDirectory(): void
    {
        $handle = OpendirJitHelper::invokeArgv('.');
        if ($handle >= 0) {
            $this->assertGreaterThanOrEqual(0, $handle);
        } else {
            $this->markTestSkipped('VmDir::opendir(".") unavailable in this environment');
        }
    }

    public function testSpineBundleIncludesOpendirJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('OpendirJitHelper.php', $spine);
        $this->assertStringContainsString('StringOpendir.php', $spine);
    }
}
