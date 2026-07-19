<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Stream read LLVM bridges quarantined in ext/standard — NestedJIT only (#19559, #20982). */
final class StreamReadBridgeKernelShrinkTest extends TestCase
{
    public function testBuiltinBridgeLlvmMovedToExtKernel(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StreamReadBridgeLlvm.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamReadBridgeKernel.php');

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamReadRuntime.php');
        $this->assertStringContainsString('JitStreamReadBridgeKernel', $runtime);
        $this->assertStringNotContainsString('StreamReadBridgeLlvm', $runtime);
        $this->assertStringNotContainsString('ensureDeferredStubsForInventoryEmit', $runtime);

        $kernel = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamReadBridgeKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $kernel);
        $this->assertStringContainsString('final class JitStreamReadBridgeKernel', $kernel);
        $this->assertStringContainsString('implementI32Bridge', $kernel);
        $this->assertStringNotContainsString('implementDeferredStubs', $kernel);
        $this->assertStringNotContainsString('implementRetStub', $kernel);
        $this->assertStringNotContainsString('stream_read_stub_entry', $kernel);
        $this->assertLessThan(220, \substr_count($kernel, "\n") + 1);
    }

    public function testSpineBundleIncludesStreamReadBridgeKernelNotBuiltinLlvm(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitStreamReadBridgeKernel.php', $spine);
        $this->assertStringContainsString('StreamReadRuntime.php', $spine);
        $this->assertStringNotContainsString('StreamReadBridgeLlvm.php', $spine);
    }
}
