<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StreamLibcHandleJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * StreamLibcHandle NestedJIT via JitVmHelperLink::ensureCompiled (#23234 / peer #23012).
 */
final class StreamLibcHandleKernelShrinkTest extends TestCase
{
    public function testBuiltinStreamLibcHandleRuntimeIsThinOrchestrator(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamLibcHandleKernel.php');
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/StreamLibcHandleRuntime.php');

        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamLibcHandleRuntime.php');
        $this->assertStringContainsString('JitStreamLibcHandleKernel', $orchestrator);
        $this->assertStringContainsString('JitStreamLibcHandleKernel::ensureLinked', $orchestrator);
        $this->assertStringNotContainsString('NestedJitCompileScope', $orchestrator);
        $this->assertStringNotContainsString('__phpc_resolve_stream', $orchestrator);
        $this->assertLessThan(50, \substr_count($orchestrator, "\n") + 1);
    }

    public function testKernelUsesJitVmHelperLinkNotHandRolledNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamLibcHandleKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $source);
        $this->assertStringContainsString('final class JitStreamLibcHandleKernel', $source);
        $this->assertStringContainsString('__phpc_resolve_stream', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('StreamLibcHandleJitHelper', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('dirname(__DIR__, 2)', $source);
        $this->assertStringNotContainsString('dirname(__DIR__, 3)', $source);
        $this->assertLessThan(230, \substr_count($source, "\n") + 1);
    }

    public function testSpineBundleIncludesKernelAndOrchestrator(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitStreamLibcHandleKernel.php', $spine);
        $this->assertStringContainsString('StreamLibcHandleRuntime.php', $spine);
        $kernelPos = strpos($spine, 'JitStreamLibcHandleKernel.php');
        $orchPos = strpos($spine, 'lib/JIT/Builtin/StreamLibcHandleRuntime.php');
        $this->assertNotFalse($kernelPos);
        $this->assertNotFalse($orchPos);
        $this->assertLessThan($orchPos, $kernelPos, 'kernel must load before thin orchestrator');
    }

    public function testStreamLibcHandleJitHelperRegisterRoundTrip(): void
    {
        StreamLibcHandleJitHelper::resetForTest();
        $this->assertSame(0, StreamLibcHandleJitHelper::resolvePtr(7));
        StreamLibcHandleJitHelper::registerFromPtr(7, 0x1234);
        $this->assertSame(0x1234, StreamLibcHandleJitHelper::resolvePtr(7));
        StreamLibcHandleJitHelper::registerFromPtr(7, 0);
        $this->assertSame(0, StreamLibcHandleJitHelper::resolvePtr(7));
    }
}
