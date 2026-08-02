<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Stream I/O libc kernel for thin AOT — NestedJIT embed only (#19530, #26929). */
final class StreamIoKernelShrinkTest extends TestCase
{
    public function testUserScriptLibcKernelInExt(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StreamIoStandaloneLlvm.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamIoKernel.php');

        $jit = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamIoJit.php');
        $this->assertStringContainsString('JitStreamIoKernel', $jit);
        $this->assertStringNotContainsString('StreamIoStandaloneLlvm', $jit);
        $this->assertStringContainsString('isThinStandaloneAotMain', $jit);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamIoRuntime.php');
        $this->assertStringContainsString('JitStreamIoKernel', $runtime);
        $this->assertStringContainsString('StreamIoJitHelper', $runtime);
    }

    public function testSpineBundleIncludesStreamIoKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitStreamIoKernel.php', $spine);
        $this->assertStringContainsString('StreamIoRuntime.php', $spine);
        $this->assertStringContainsString('StreamIoJitHelper.php', $spine);
        $this->assertStringNotContainsString('StreamIoStandaloneLlvm.php', $spine);
    }
}
