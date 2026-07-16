<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Stream I/O user-script AOT libc kernel quarantined in ext/standard (#19530, #19462). */
final class StreamIoKernelShrinkTest extends TestCase
{
    public function testUserScriptStandaloneLlvmMovedToExtKernel(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StreamIoStandaloneLlvm.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamIoKernel.php');

        $jit = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamIoJit.php');
        $this->assertStringContainsString('JitStreamIoKernel', $jit);
        $this->assertStringNotContainsString('StreamIoStandaloneLlvm', $jit);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamIoRuntime.php');
        $this->assertStringContainsString('JitStreamIoKernel', $runtime);
        $this->assertStringNotContainsString('StreamIoStandaloneLlvm', $runtime);

        $kernel = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamIoKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $kernel);
        $this->assertStringContainsString('final class JitStreamIoKernel', $kernel);
        $this->assertStringContainsString('implementForUserScriptLowering', $kernel);
        $this->assertStringContainsString('emitStreamSupports', $kernel);
    }

    public function testSpineBundleIncludesStreamIoKernelNotBuiltinStandalone(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitStreamIoKernel.php', $spine);
        $this->assertStringContainsString('StreamIoRuntime.php', $spine);
        $this->assertStringNotContainsString('StreamIoStandaloneLlvm.php', $spine);
    }
}
