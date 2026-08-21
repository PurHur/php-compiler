<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_stream_get_line ABI shell from Builtin\Type (#33170).
 *
 * NestedJIT/AOT bridge stays StreamRead / StreamReadRuntime / JitStreamReadBridgeKernel.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint stream_get_line.1 (#31894 / #32122).
 */
final class TypeDeadStreamGetLineAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnStreamGetLineAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33170', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_stream_get_line[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_stream_get_line (#33170)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_stream_get_line'",
            $type,
            'Builtin\\Type must not always-register __compiler_stream_get_line (#33170)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel after fseek drop (#33177); stream_get_contents still Type always-on.
        $this->assertStringContainsString("registerFunction('__compiler_stream_get_contents'", $type);
        $this->assertStringContainsString('StreamRead::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresStreamGetLineAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamReadBridgeKernel.php');
        $this->assertStringContainsString('#33170', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('implementStreamGetLineBridge', $owner);
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamReadRuntime.php');
        $this->assertStringContainsString('#33170', $runtime);
        $this->assertStringContainsString('getNamedFunction', $runtime);
        $this->assertStringContainsString('__compiler_stream_get_line', $runtime);
        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamRead.php');
        $this->assertStringContainsString('#33170', $orchestrator);
        $this->assertFileExists(__DIR__.'/../../ext/standard/StreamReadJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamReadBridgeKernel.php');
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamGetLine.php');
        $this->assertStringContainsString('#33170', $jit);
        $this->assertStringContainsString('StreamReadRuntime::ensureLinked', $jit);
    }

    public function testTypeInitializeStillEnsureLinksStreamRead(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StreamRead::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForStreamGetLineAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/stream_get_line.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/stream_get_line.c');
    }
}
