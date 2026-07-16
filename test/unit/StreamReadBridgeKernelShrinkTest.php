<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Stream read LLVM bridges quarantined in ext/standard (#19559, #18672). */
final class StreamReadBridgeKernelShrinkTest extends TestCase
{
    public function testBuiltinBridgeLlvmMovedToExtKernel(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StreamReadBridgeLlvm.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamReadBridgeKernel.php');

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamReadRuntime.php');
        $this->assertStringContainsString('JitStreamReadBridgeKernel', $runtime);
        $this->assertStringNotContainsString('StreamReadBridgeLlvm', $runtime);

        $kernel = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamReadBridgeKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $kernel);
        $this->assertStringContainsString('final class JitStreamReadBridgeKernel', $kernel);
        $this->assertStringContainsString('implementI32Bridge', $kernel);
        $this->assertStringContainsString('implementDeferredStubs', $kernel);
    }

    public function testSpineBundleIncludesStreamReadBridgeKernelNotBuiltinLlvm(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitStreamReadBridgeKernel.php', $spine);
        $this->assertStringContainsString('StreamReadRuntime.php', $spine);
        $this->assertStringNotContainsString('StreamReadBridgeLlvm.php', $spine);
    }
}
