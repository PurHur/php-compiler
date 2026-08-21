<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_format_datetime ABI shell from Builtin\Type (#33215).
 *
 * NestedJIT/AOT bridge stays StringDateTime / FormatDatetimeJitHelper / JitDate.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint format_datetime.1 (#31894 / #32122).
 */
final class TypeDeadFormatDatetimeAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnFormatDatetimeAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33215', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_format_datetime[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_format_datetime (#33215)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_format_datetime'",
            $type,
            'Builtin\\Type must not always-register __compiler_format_datetime (#33215)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (phpc_deploy_path still Type always-on; #33225).
        $this->assertStringContainsString("registerFunction('__compiler_phpc_deploy_path'", $type);
        $this->assertStringContainsString('StringDateTime', $type);
    }

    public function testRuntimeOwnerDeclaresFormatDatetimeAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringDateTime.php');
        $this->assertStringContainsString('#33215', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('implementFormatDatetimeBridge', $owner);
        $this->assertStringContainsString('__compiler_format_datetime', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/FormatDatetimeJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitDate.php');
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitDate.php');
        $this->assertStringContainsString('#33215', $jit);
        $this->assertStringContainsString('StringDateTime::ensureLinked', $jit);
    }

    public function testTypeInitializeStillEnsureLinksStringDateTime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringDateTime::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForFormatDatetimeAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/format_datetime.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/format_datetime.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_format_datetime.c');
    }
}
