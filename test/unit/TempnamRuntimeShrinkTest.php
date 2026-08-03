<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FsDirJitHelper;
use PHPCompiler\ext\standard\TempnamJitHelper;
use PHPCompiler\ext\standard\VmSysGetTempDirNative;
use PHPUnit\Framework\TestCase;

/** tempnam() JIT routes through TempnamJitHelper PHP not inline LLVM in JitTempnam (#15685). */
final class TempnamRuntimeShrinkTest extends TestCase
{
    public function testJitTempnamDelegatesToStringTempnamBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitTempnam.php');
        $this->assertStringContainsString('StringTempnam::invoke', $source);
        $this->assertStringNotContainsString('BasicBlockHelper', $source);
        $this->assertStringNotContainsString('__compiler_tempnam', $source);
        $this->assertLessThan(50, \substr_count($source, "\n") + 1);
    }

    public function testStringTempnamUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringTempnam.php');
        $this->assertStringContainsString('TempnamJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink', $source);
        $this->assertStringContainsString('ensureCompiledBundle', $source);
        $this->assertStringContainsString('FsDirJitHelper.php', $source);
        // Thin AOT: libc mkstemp kernel — NestedJIT host-fopen cannot create (#27089).
        $this->assertStringContainsString('JitTempnamKernel::implementForThinAot', $source);
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringContainsString('tryGetInsertBlock', $source);
        $this->assertStringNotContainsString('BasicBlockHelper::append(', $source);
        $this->assertStringNotContainsString("lookupFunction('mkstemp')", $source);
    }

    public function testJitTempnamKernelUsesLibcMkstemp(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitTempnamKernel.php');
        $this->assertStringContainsString("lookupFunction('mkstemp')", $source);
        $this->assertStringContainsString('SysGetTempDirRuntime::ensureLinked', $source);
        $this->assertStringContainsString('__phpc_jit_tempnam', $source);
    }

    public function testTempnamJitHelperMatchesFsDirHelper(): void
    {
        FsDirJitHelper::resetForTest();
        $dir = VmSysGetTempDirNative::resolve();
        $path = TempnamJitHelper::resolveArgv($dir, 'phpc');
        $this->assertNotNull($path);
        $this->assertStringStartsWith($dir, $path);
        $this->assertFileExists($path);
        @unlink($path);
    }
}
