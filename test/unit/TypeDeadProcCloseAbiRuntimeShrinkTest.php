<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_proc_close ABI shell from Builtin\Type (#33118).
 *
 * NestedJIT/AOT bridge stays ProcessOpenEmbedBridge / ProcessOpenJitHelper /
 * JitProcClose (implementI32Bridge). Runtime owner declares module-locally
 * (getNamedFunction first) so leftover Type empty decls cannot mint proc_close.1
 * (#31894 / #32122).
 */
final class TypeDeadProcCloseAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnProcCloseAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33118', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_proc_close[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_proc_close (#33118)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_proc_close'",
            $type,
            'Builtin\\Type must not always-register __compiler_proc_close (#33118)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (assert_options still Type always-on; #33234 trigger_error / #33241 assert_fail_string dropped).
        $this->assertStringContainsString("registerFunction('__compiler_assert_options'", $type);
        $this->assertStringContainsString('ProcessOpen::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresProcCloseAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ProcessOpenEmbedBridge.php');
        $this->assertStringContainsString('#33118', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_proc_close', $owner);
        $this->assertStringContainsString('implementI32Bridge', $owner);
        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ProcessOpen.php');
        $this->assertStringContainsString('#33118', $orchestrator);
        $this->assertFileExists(__DIR__.'/../../ext/standard/ProcessOpenJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitProcClose.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/proc_open.php');
    }

    public function testTypeInitializeStillEnsureLinksProcessOpen(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('ProcessOpen::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForProcCloseAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/proc_close.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/proc_close.c');
    }
}
