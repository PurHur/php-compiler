<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_stream_isatty ABI shell from Builtin\Type (#33151).
 *
 * NestedJIT/AOT bridge stays StreamCaps / StreamCapsRuntime / JitStreamCapsKernel.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint stream_isatty.1 (#31894 / #32122).
 */
final class TypeDeadStreamIsattyAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnStreamIsattyAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33151', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_stream_isatty[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_stream_isatty (#33151)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_stream_isatty'",
            $type,
            'Builtin\\Type must not always-register __compiler_stream_isatty (#33151)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (get_resource_type still Type always-on; stream_get_contents dropped in #33178).
        $this->assertStringContainsString("registerFunction('__compiler_get_resource_type'", $type);
        $this->assertStringContainsString('StreamCaps::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresStreamIsattyAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamCapsKernel.php');
        $this->assertStringContainsString('#33151', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_stream_isatty', $owner);
        $this->assertStringContainsString('implementSingleArgBridge', $owner);
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamCapsRuntime.php');
        $this->assertStringContainsString('#33151', $runtime);
        $this->assertStringContainsString('getNamedFunction', $runtime);
        $this->assertStringContainsString('__compiler_stream_isatty', $runtime);
        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamCaps.php');
        $this->assertStringContainsString('#33151', $orchestrator);
        $this->assertFileExists(__DIR__.'/../../ext/standard/StreamCapsJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamCapsKernel.php');
    }

    public function testTypeInitializeStillEnsureLinksStreamCaps(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StreamCaps::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForStreamIsattyAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/stream_isatty.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/stream_isatty.c');
    }
}
