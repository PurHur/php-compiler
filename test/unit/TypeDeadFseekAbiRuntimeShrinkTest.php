<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_fseek ABI shell from Builtin\Type (#33177).
 *
 * NestedJIT/AOT bridge stays StreamRead / StreamReadRuntime / JitStreamReadBridgeKernel.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint fseek.1 (#31894 / #32122).
 */
final class TypeDeadFseekAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnFseekAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33177', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_fseek[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_fseek (#33177)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_fseek'",
            $type,
            'Builtin\\Type must not always-register __compiler_fseek (#33177)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel after fseek drop (#33177).
        $this->assertStringContainsString("registerFunction('__compiler_stream_get_contents'", $type);
        $this->assertStringContainsString('StreamRead::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresFseekAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamReadBridgeKernel.php');
        $this->assertStringContainsString('#33177', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('implementI64Bridge', $owner);
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamReadRuntime.php');
        $this->assertStringContainsString('#33177', $runtime);
        $this->assertStringContainsString('getNamedFunction', $runtime);
        $this->assertStringContainsString('__compiler_fseek', $runtime);
        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamRead.php');
        $this->assertStringContainsString('#33177', $orchestrator);
        $this->assertFileExists(__DIR__.'/../../ext/standard/StreamIoJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamReadBridgeKernel.php');
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitFseek.php');
        $this->assertStringContainsString('#33177', $jit);
        $this->assertStringContainsString('StreamReadRuntime::ensureLinked', $jit);
    }

    public function testTypeInitializeStillEnsureLinksStreamRead(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StreamRead::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForFseekAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/fseek.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/fseek.c');
    }
}
