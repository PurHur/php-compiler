<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_fsync ABI shell from Builtin\Type (#33114).
 *
 * NestedJIT/AOT bridge stays StreamSync / JitStreamSyncKernel (implementIfMissing).
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint fsync.1 (#31894 / #32122).
 */
final class TypeDeadFsyncAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnFsyncAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33114', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_fsync[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_fsync (#33114)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_fsync'",
            $type,
            'Builtin\\Type must not always-register __compiler_fsync (#33114)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (stream_is_local still Type always-on; stream_supports dropped in #33145).
        $this->assertStringContainsString("registerFunction('__compiler_stream_is_local'", $type);
        $this->assertStringContainsString('StreamSync::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresFsyncAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamSyncKernel.php');
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_fsync', $owner);
        $this->assertStringContainsString('implementIfMissing', $owner);
        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamSync.php');
        $this->assertStringContainsString('#33114', $orchestrator);
        $this->assertFileExists(__DIR__.'/../../ext/standard/StreamSyncJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamSyncKernel.php');
    }

    public function testTypeInitializeStillEnsureLinksStreamSync(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StreamSync::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForFsyncAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/fsync.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/fsync.c');
    }
}
