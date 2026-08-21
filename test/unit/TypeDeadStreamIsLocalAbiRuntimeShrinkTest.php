<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_stream_is_local ABI shell from Builtin\Type (#33148).
 *
 * NestedJIT/AOT bridge stays StreamCaps / StreamCapsRuntime / JitStreamCapsKernel.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint stream_is_local.1 (#31894 / #32122).
 */
final class TypeDeadStreamIsLocalAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnStreamIsLocalAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33148', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_stream_is_local[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_stream_is_local (#33148)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_stream_is_local'",
            $type,
            'Builtin\\Type must not always-register __compiler_stream_is_local (#33148)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (str_getcsv still Type always-on after #33199 preg_split drop).
        $this->assertStringContainsString("registerFunction('__compiler_str_getcsv'", $type);
        $this->assertStringContainsString('StreamCaps::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresStreamIsLocalAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamCapsKernel.php');
        $this->assertStringContainsString('#33148', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_stream_is_local', $owner);
        $this->assertStringContainsString('implementSingleArgBridge', $owner);
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamCapsRuntime.php');
        $this->assertStringContainsString('#33148', $runtime);
        $this->assertStringContainsString('getNamedFunction', $runtime);
        $this->assertStringContainsString('__compiler_stream_is_local', $runtime);
        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamCaps.php');
        $this->assertStringContainsString('#33148', $orchestrator);
        $this->assertFileExists(__DIR__.'/../../ext/standard/StreamCapsJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamCapsKernel.php');
    }

    public function testTypeInitializeStillEnsureLinksStreamCaps(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StreamCaps::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForStreamIsLocalAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/stream_is_local.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/stream_is_local.c');
    }
}
