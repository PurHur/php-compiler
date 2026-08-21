<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_feof ABI shell from Builtin\Type (#33080).
 *
 * NestedJIT/AOT bridge stays StreamLifecycleRuntime + JitStreamLifecycleKernel /
 * StreamLifecycleJitHelper. Runtime owner declares module-locally (getNamedFunction
 * first) so leftover Type empty decls cannot mint feof.1 (#31894 / #32122).
 */
final class TypeDeadFeofAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnFeofAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33080', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_feof[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_feof (#33080)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_feof'",
            $type,
            'Builtin\\Type must not always-register __compiler_feof (#33080)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (proc_close still Type always-on).
        $this->assertStringContainsString("registerFunction('__compiler_proc_close'", $type);
        $this->assertStringContainsString('StreamLifecycle::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresFeofAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamLifecycleKernel.php');
        $this->assertStringContainsString('#33080', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_feof', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/StreamLifecycleJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitFeof.php');
    }

    public function testTypeInitializeStillEnsureLinksStreamLifecycle(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StreamLifecycle::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForFeofAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/feof.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/feof.c');
    }
}
