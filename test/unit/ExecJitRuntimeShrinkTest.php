<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** exec/passthru/system JIT lowering via JitExec + ProcessRuntime (#8640). */
final class ExecJitRuntimeShrinkTest extends TestCase
{
    public function testExecDispatchesJitExec(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/exec.php');
        $this->assertStringContainsString('JitExec::exec', $source);
        $this->assertStringNotContainsString('not implemented for JIT', $source);
    }

    public function testPassthruAndSystemDispatchJitExec(): void
    {
        $passthru = (string) file_get_contents(__DIR__.'/../../ext/standard/passthru.php');
        $system = (string) file_get_contents(__DIR__.'/../../ext/standard/system.php');
        $this->assertStringContainsString('JitExec::passthru', $passthru);
        $this->assertStringContainsString('JitExec::system', $system);
        $this->assertStringNotContainsString('not implemented for JIT', $passthru);
        $this->assertStringNotContainsString('not implemented for JIT', $system);
    }

    public function testProcessRuntimeDeclaresExecCapture(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ProcessRuntime.php');
        $this->assertStringContainsString('__compiler_process_exec_capture', $source);
        $this->assertStringContainsString('ProcessExecCaptureNativeJitHelper', $source);
        $this->assertStringContainsString('phpc_native_ht_set_string_key_long', $source);
    }

    public function testNoNewAotRuntimeCSources(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        $cFiles = glob($runtimeDir.'/*.c') ?: [];
        sort($cFiles);
        $this->assertSame(
            [],
            $cFiles,
            'exec JIT must not add C runtime TUs'
        );
    }
}
