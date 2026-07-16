<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Exec-capture ob LLVM quarantined in ext/standard (#19576, #10492). */
final class ObOutputExecCaptureKernelShrinkTest extends TestCase
{
    public function testBuiltinLlvmMovedToExtKernel(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/ObOutputExecCaptureLlvm.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitObOutputExecCaptureKernel.php');

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ObOutputExecCaptureRuntime.php');
        $this->assertStringContainsString('JitObOutputExecCaptureKernel', $runtime);
        $this->assertStringNotContainsString('ObOutputExecCaptureLlvm', $runtime);

        $kernel = (string) file_get_contents(__DIR__.'/../../ext/standard/JitObOutputExecCaptureKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $kernel);
        $this->assertStringContainsString('final class JitObOutputExecCaptureKernel', $kernel);
        $this->assertStringContainsString('ensureLinked', $kernel);
        $this->assertStringContainsString('ensureReadApiLinked', $kernel);
    }

    public function testSpineBundleIncludesExecCaptureKernelNotBuiltinLlvm(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitObOutputExecCaptureKernel.php', $spine);
        $this->assertStringContainsString('ObOutputExecCaptureRuntime.php', $spine);
        $this->assertStringNotContainsString('ObOutputExecCaptureLlvm.php', $spine);
    }
}
