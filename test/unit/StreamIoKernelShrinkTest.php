<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Stream I/O libc kernel deleted — NestedJIT StreamIoJitHelper only (#20943, was #19530). */
final class StreamIoKernelShrinkTest extends TestCase
{
    public function testUserScriptLibcKernelDeleted(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StreamIoStandaloneLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitStreamIoKernel.php');

        $jit = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamIoJit.php');
        $this->assertStringNotContainsString('JitStreamIoKernel', $jit);
        $this->assertStringNotContainsString('StreamIoStandaloneLlvm', $jit);
        $this->assertStringContainsString('StreamIoRuntime::ensureLinked', $jit);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamIoRuntime.php');
        $this->assertStringNotContainsString('JitStreamIoKernel', $runtime);
        $this->assertStringNotContainsString('StreamIoStandaloneLlvm', $runtime);
        $this->assertStringContainsString('StreamIoJitHelper', $runtime);
    }

    public function testSpineBundleIncludesStreamIoPhpNotKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitStreamIoKernel.php', $spine);
        $this->assertStringContainsString('StreamIoRuntime.php', $spine);
        $this->assertStringContainsString('StreamIoJitHelper.php', $spine);
        $this->assertStringNotContainsString('StreamIoStandaloneLlvm.php', $spine);
    }
}
