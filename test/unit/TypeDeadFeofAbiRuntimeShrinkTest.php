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
        // No further Type always-on leftover after exit/abort drop (#33267).
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]exit[\'"]/',
            $type,
            'Builtin\\Type must not always-declare exit (#33267)'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]abort[\'"]/',
            $type,
            'Builtin\\Type must not always-declare abort (#33267)'
        );
        // Next leftover sentinel (trigger_error still Type always-on; #33224 strptime / #33222 strftime / #33215 format_datetime dropped).
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
