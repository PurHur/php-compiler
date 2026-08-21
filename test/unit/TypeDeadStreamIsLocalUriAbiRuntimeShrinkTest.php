<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_stream_is_local_uri ABI shell from Builtin\Type (#33150).
 *
 * NestedJIT/AOT bridge stays StreamCaps / StreamCapsRuntime / JitStreamCapsKernel.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint stream_is_local_uri.1 (#31894 / #32122).
 */
final class TypeDeadStreamIsLocalUriAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnStreamIsLocalUriAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33150', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_stream_is_local_uri[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_stream_is_local_uri (#33150)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_stream_is_local_uri'",
            $type,
            'Builtin\\Type must not always-register __compiler_stream_is_local_uri (#33150)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (phpc_deploy_path still Type always-on; #33225).
        $this->assertStringContainsString("registerFunction('__compiler_phpc_deploy_path'", $type);
        $this->assertStringContainsString('StreamCaps::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresStreamIsLocalUriAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamCapsKernel.php');
        $this->assertStringContainsString('#33150', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_stream_is_local_uri', $owner);
        $this->assertStringContainsString('implementIfMissing', $owner);
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamCapsRuntime.php');
        $this->assertStringContainsString('#33150', $runtime);
        $this->assertStringContainsString('getNamedFunction', $runtime);
        $this->assertStringContainsString('__compiler_stream_is_local_uri', $runtime);
        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamCaps.php');
        $this->assertStringContainsString('#33150', $orchestrator);
        $this->assertFileExists(__DIR__.'/../../ext/standard/StreamCapsJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamCapsKernel.php');
    }

    public function testTypeInitializeStillEnsureLinksStreamCaps(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StreamCaps::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForStreamIsLocalUriAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/stream_is_local_uri.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/stream_is_local_uri.c');
    }
}
