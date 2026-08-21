<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_pclose ABI shell from Builtin\Type (#33093).
 *
 * NestedJIT/AOT bridge stays StreamLifecycleRuntime + JitStreamLifecycleKernel /
 * StreamLifecycleJitHelper (implementCloseBridge). Runtime owner declares
 * module-locally (getNamedFunction first) so leftover Type empty decls cannot
 * mint pclose.1 (#31894 / #32122).
 */
final class TypeDeadPcloseAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnPcloseAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33093', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_pclose[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_pclose (#33093)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_pclose'",
            $type,
            'Builtin\\Type must not always-register __compiler_pclose (#33093)'
        );
        // No further Type always-on leftover after #33267 exit/abort drop.
        $this->assertStringContainsString('LibcExtern::ensureExitAbort', $type);
        $this->assertStringContainsString('StreamLifecycle::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresPcloseAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamLifecycleKernel.php');
        $this->assertStringContainsString('#33093', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_pclose', $owner);
        $this->assertStringContainsString('implementCloseBridge', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/StreamLifecycleJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitPclose.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/pclose.php');
    }

    public function testTypeInitializeStillEnsureLinksStreamLifecycle(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StreamLifecycle::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForPcloseAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/pclose.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/pclose.c');
    }
}
