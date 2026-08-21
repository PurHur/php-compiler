<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_fgets ABI shell from Builtin\Type (#33168).
 *
 * NestedJIT/AOT bridge stays StreamRead / StreamReadRuntime / JitStreamReadBridgeKernel.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint fgets.1 (#31894 / #32122).
 */
final class TypeDeadFgetsAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnFgetsAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33168', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_fgets[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_fgets (#33168)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_fgets'",
            $type,
            'Builtin\\Type must not always-register __compiler_fgets (#33168)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (stream_get_line still Type always-on; fgetc/fgets dropped).
        $this->assertStringContainsString("registerFunction('__compiler_stream_get_line'", $type);
        $this->assertStringContainsString('StreamRead::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresFgetsAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamReadBridgeKernel.php');
        $this->assertStringContainsString('#33168', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('implementNullableStringBridge', $owner);
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamReadRuntime.php');
        $this->assertStringContainsString('#33168', $runtime);
        $this->assertStringContainsString('getNamedFunction', $runtime);
        $this->assertStringContainsString('__compiler_fgets', $runtime);
        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamRead.php');
        $this->assertStringContainsString('#33168', $orchestrator);
        $this->assertFileExists(__DIR__.'/../../ext/standard/StreamReadJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamReadBridgeKernel.php');
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitFgets.php');
        $this->assertStringContainsString('#33168', $jit);
        $this->assertStringContainsString('StreamReadRuntime::ensureLinked', $jit);
    }

    public function testTypeInitializeStillEnsureLinksStreamRead(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StreamRead::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForFgetsAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/fgets.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/fgets.c');
    }
}
