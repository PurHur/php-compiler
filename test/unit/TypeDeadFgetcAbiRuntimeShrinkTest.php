<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_fgetc ABI shell from Builtin\Type (#33166).
 *
 * NestedJIT/AOT bridge stays StreamRead / StreamReadRuntime / JitStreamReadBridgeKernel.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint fgetc.1 (#31894 / #32122).
 */
final class TypeDeadFgetcAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnFgetcAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33166', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_fgetc[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_fgetc (#33166)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_fgetc'",
            $type,
            'Builtin\\Type must not always-register __compiler_fgetc (#33166)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (fseek still Type always-on; stream_get_line dropped in #33170).
        $this->assertStringContainsString("registerFunction('__compiler_fseek'", $type);
        $this->assertStringContainsString('StreamRead::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresFgetcAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamReadBridgeKernel.php');
        $this->assertStringContainsString('#33166', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('implementNullableStringBridge', $owner);
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamReadRuntime.php');
        $this->assertStringContainsString('#33166', $runtime);
        $this->assertStringContainsString('getNamedFunction', $runtime);
        $this->assertStringContainsString('__compiler_fgetc', $runtime);
        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamRead.php');
        $this->assertStringContainsString('#33166', $orchestrator);
        $this->assertFileExists(__DIR__.'/../../ext/standard/StreamReadJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamReadBridgeKernel.php');
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitFgetc.php');
        $this->assertStringContainsString('#33166', $jit);
        $this->assertStringContainsString('StreamReadRuntime::ensureLinked', $jit);
    }

    public function testTypeInitializeStillEnsureLinksStreamRead(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StreamRead::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForFgetcAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/fgetc.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/fgetc.c');
    }
}
