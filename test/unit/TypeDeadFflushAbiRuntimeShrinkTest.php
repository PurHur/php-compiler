<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_fflush ABI shell from Builtin\Type (#33084).
 *
 * NestedJIT/AOT bridge stays StreamLifecycleRuntime + JitStreamLifecycleKernel /
 * StreamLifecycleJitHelper. Runtime owner declares module-locally (getNamedFunction
 * first) so leftover Type empty decls cannot mint fflush.1 (#31894 / #32122).
 */
final class TypeDeadFflushAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnFflushAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33084', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_fflush[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_fflush (#33084)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_fflush'",
            $type,
            'Builtin\\Type must not always-register __compiler_fflush (#33084)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (proc_close still Type always-on for this peer guard).
        $this->assertStringContainsString("registerFunction('__compiler_proc_close'", $type);
        $this->assertStringContainsString('StreamLifecycle::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresFflushAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamLifecycleKernel.php');
        $this->assertStringContainsString('#33084', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_fflush', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/StreamLifecycleJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitFflush.php');
    }

    public function testTypeInitializeStillEnsureLinksStreamLifecycle(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StreamLifecycle::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForFflushAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/fflush.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/fflush.c');
    }
}
