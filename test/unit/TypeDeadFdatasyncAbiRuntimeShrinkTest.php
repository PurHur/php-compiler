<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_fdatasync ABI shell from Builtin\Type (#33123).
 *
 * NestedJIT/AOT bridge stays StreamSync / JitStreamSyncKernel (implementIfMissing).
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint fdatasync.1 (#31894 / #32122).
 */
final class TypeDeadFdatasyncAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnFdatasyncAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33123', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_fdatasync[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_fdatasync (#33123)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_fdatasync'",
            $type,
            'Builtin\\Type must not always-register __compiler_fdatasync (#33123)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (undefined_array_key_warning_cstr still Type always-on; #33245 assert_options dropped).
        $this->assertStringContainsString("registerFunction('__compiler_undefined_array_key_warning_cstr'", $type);
        $this->assertStringContainsString('StreamSync::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresFdatasyncAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamSyncKernel.php');
        $this->assertStringContainsString('#33123', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_fdatasync', $owner);
        $this->assertStringContainsString('implementIfMissing', $owner);
        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamSync.php');
        $this->assertStringContainsString('#33123', $orchestrator);
        $this->assertFileExists(__DIR__.'/../../ext/standard/StreamSyncJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamSyncKernel.php');
    }

    public function testTypeInitializeStillEnsureLinksStreamSync(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StreamSync::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForFdatasyncAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/fdatasync.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/fdatasync.c');
    }
}
