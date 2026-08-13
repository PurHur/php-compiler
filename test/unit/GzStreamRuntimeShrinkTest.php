<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * GzStreamRuntime NestedJIT via JitVmHelperLink::ensureCompiled (#22431 / peer #22416).
 * Must route through GzStreamJitHelper PHP (#13420).
 */
final class GzStreamRuntimeShrinkTest extends TestCase
{
    public function testGzStreamRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GzStreamRuntime.php');
        $this->assertStringContainsString('GzStreamJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringContainsString('StreamIoRuntime::isDeferStub', $source);
        $this->assertStringContainsString('JitGzStreamKernel', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
    }

    public function testGzStreamJitHelperDelegatesToVmGzStream(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/GzStreamJitHelper.php');
        $this->assertStringContainsString('VmGzStream', $source);
        $this->assertFileExists(__DIR__.'/../../ext/standard/GzStreamJitHelper.php');
    }

    public function testJitGzStreamKernelExistsForThinAot(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitGzStreamKernel.php');
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitGzStreamKernel.php');
        $this->assertStringContainsString('gzwrite', $source);
        $this->assertStringContainsString('#30787', $source);
    }
}
