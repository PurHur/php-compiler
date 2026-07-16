<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * get_resource_type()/get_resources() LLVM quarantined in ext/standard (#5179, #19613).
 */
final class StreamResourceKernelShrinkTest extends TestCase
{
    public function testBuiltinStreamResourceJitMovedToExtKernel(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StreamResourceJit.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamResourceKernel.php');

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamResource.php');
        $this->assertStringContainsString('JitStreamResourceKernel', $runtime);
        $this->assertStringNotContainsString('StreamResourceJit', $runtime);
    }

    public function testKernelPresent(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamResourceKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $source);
        $this->assertStringContainsString('final class JitStreamResourceKernel', $source);
        $this->assertStringContainsString('__compiler_get_resource_type', $source);
        $this->assertStringContainsString('__compiler_get_resources', $source);
    }

    public function testSpineBundleIncludesKernelNotBuiltinJit(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitStreamResourceKernel.php', $spine);
        $this->assertStringNotContainsString('StreamResourceJit.php', $spine);
        $this->assertStringContainsString('StreamResource.php', $spine);
    }
}
