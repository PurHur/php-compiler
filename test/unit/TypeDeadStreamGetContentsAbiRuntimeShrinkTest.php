<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_stream_get_contents ABI shell from Builtin\Type (#33178).
 *
 * NestedJIT/AOT bridge stays StreamRead / StreamReadRuntime / JitStreamReadBridgeKernel.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint stream_get_contents.1 (#31894 / #32122).
 */
final class TypeDeadStreamGetContentsAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnStreamGetContentsAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33178', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_stream_get_contents[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_stream_get_contents (#33178)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_stream_get_contents'",
            $type,
            'Builtin\\Type must not always-register __compiler_stream_get_contents (#33178)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (phpc_deploy_path still Type always-on; #33225).
        $this->assertStringContainsString("registerFunction('__compiler_phpc_deploy_path'", $type);
        $this->assertStringContainsString('StreamRead::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresStreamGetContentsAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamReadBridgeKernel.php');
        $this->assertStringContainsString('#33178', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('implementNullableStringBridge', $owner);
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamReadRuntime.php');
        $this->assertStringContainsString('#33178', $runtime);
        $this->assertStringContainsString('getNamedFunction', $runtime);
        $this->assertStringContainsString('__compiler_stream_get_contents', $runtime);
        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamRead.php');
        $this->assertStringContainsString('#33178', $orchestrator);
        $this->assertFileExists(__DIR__.'/../../ext/standard/StreamReadJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamReadBridgeKernel.php');
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamGetContents.php');
        $this->assertStringContainsString('#33178', $jit);
        $this->assertStringContainsString('StreamReadRuntime::ensureLinked', $jit);
    }

    public function testTypeInitializeStillEnsureLinksStreamRead(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StreamRead::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForStreamGetContentsAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/stream_get_contents.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/stream_get_contents.c');
    }
}
