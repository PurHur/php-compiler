<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_stream_set_blocking ABI shell from Builtin\Type (#33157).
 *
 * NestedJIT/AOT bridge stays StreamMeta / JitStreamMetaKernel / JitStreamMetaThinAot.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint stream_set_blocking.1 (#31894 / #32122).
 */
final class TypeDeadStreamSetBlockingAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnStreamSetBlockingAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33157', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_stream_set_blocking[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_stream_set_blocking (#33157)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_stream_set_blocking'",
            $type,
            'Builtin\\Type must not always-register __compiler_stream_set_blocking (#33157)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (strptime still Type always-on; #33222 strftime / #33215 format_datetime dropped).
        $this->assertStringContainsString("registerFunction('__compiler_strptime'", $type);
        $this->assertStringContainsString('StreamMeta::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresStreamSetBlockingAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamMetaKernel.php');
        $this->assertStringContainsString('#33157', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_stream_set_blocking', $owner);
        $this->assertStringContainsString('implementIfMissing', $owner);
        $this->assertStringContainsString('implementSetBlockingBridge', $owner);
        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamMeta.php');
        $this->assertStringContainsString('#33157', $orchestrator);
        $this->assertStringContainsString('__compiler_stream_set_blocking', $orchestrator);
        $thin = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamMetaThinAot.php');
        $this->assertStringContainsString('#33157', $thin);
        $this->assertFileExists(__DIR__.'/../../ext/standard/StreamMetaJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamMetaKernel.php');
    }

    public function testTypeInitializeStillEnsureLinksStreamMeta(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StreamMeta::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForStreamSetBlockingAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/stream_set_blocking.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/stream_set_blocking.c');
    }
}
