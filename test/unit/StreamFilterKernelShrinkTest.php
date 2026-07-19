<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * stream_filter_* ABI bridges always NestedJIT — no thin deferred stubs (#9047, #21041).
 */
final class StreamFilterKernelShrinkTest extends TestCase
{
    public function testBuiltinStreamFilterJitMovedToExtKernel(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StreamFilterJit.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamFilterKernel.php');

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamFilter.php');
        $this->assertStringContainsString('JitStreamFilterKernel', $runtime);
        $this->assertStringNotContainsString('StreamFilterJit', $runtime);
    }

    public function testKernelAlwaysNestedJitNoDeferredStubs(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamFilterKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $source);
        $this->assertStringContainsString('final class JitStreamFilterKernel', $source);
        $this->assertStringContainsString('__compiler_stream_filter_append', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('VmActiveContextInitLlvm::requestThinStandaloneInit', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringNotContainsString('implementDeferredInventoryStubs', $source);
        $this->assertStringNotContainsString('ensureDeferredStubsForInventoryEmit', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('isStandaloneInitPhase', $source);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $source);
    }

    public function testSpineBundleIncludesKernelNotBuiltinJit(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitStreamFilterKernel.php', $spine);
        $this->assertStringNotContainsString('StreamFilterJit.php', $spine);
        $this->assertStringContainsString('StreamFilter.php', $spine);
    }
}
