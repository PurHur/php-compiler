<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Exec-capture ob: always JitVmHelperLink + ObOutputExecCaptureJitHelper (#21476).
 * Former JitObOutputExecCaptureKernel LLVM fork deleted.
 */
final class ObOutputExecCaptureKernelShrinkTest extends TestCase
{
    public function testExecCaptureKernelDeletedUsesHelperLink(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/ObOutputExecCaptureLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitObOutputExecCaptureKernel.php');

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ObOutputExecCaptureRuntime.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $runtime);
        $this->assertStringContainsString('ObOutputExecCaptureJitHelper', $runtime);
        $this->assertStringNotContainsString('JitObOutputExecCaptureKernel', $runtime);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $runtime);
        $this->assertStringNotContainsString('ObOutputExecCaptureLlvm', $runtime);

        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/ObOutputExecCaptureJitHelper.php');
        $this->assertStringContainsString('phpc_ob_write_stdout_kernel', $helper);
        $this->assertStringNotContainsString('echo $chunk', $helper);
    }

    public function testSpineBundleDropsExecCaptureKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitObOutputExecCaptureKernel.php', $spine);
        $this->assertStringContainsString('ObOutputExecCaptureRuntime.php', $spine);
        $this->assertStringContainsString('ObOutputExecCaptureJitHelper.php', $spine);
        $this->assertStringNotContainsString('ObOutputExecCaptureLlvm.php', $spine);
    }
}
