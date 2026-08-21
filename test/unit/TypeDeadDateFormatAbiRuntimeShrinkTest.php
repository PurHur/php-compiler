<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_format_datetime ABI shell from Builtin\Type (#33217).
 *
 * NestedJIT/AOT bridge stays StringDateTime / FormatDatetimeJitHelper / JitDate.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint format_datetime.1 (#31894 / #32122).
 *
 * strftime/strptime Type always-on shells stay this run — thin-AOT NestedJIT still
 * segfaults/aborts once ensureLinked (JIT OK; master was link-fail without a body).
 */
final class TypeDeadDateFormatAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnFormatDatetimeAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33217', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_format_datetime[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_format_datetime (#33217)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_format_datetime'",
            $type,
            'Builtin\\Type must not always-register __compiler_format_datetime (#33217)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (strftime still Type always-on after #33217 format_datetime drop).
        $this->assertStringContainsString("registerFunction('__compiler_strftime'", $type);
        $this->assertStringContainsString('StringDateTime', $type);
    }

    public function testRuntimeOwnerDeclaresFormatDatetimeAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringDateTime.php');
        $this->assertStringContainsString('#33217', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('__compiler_format_datetime', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/FormatDatetimeJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitDate.php');
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitDate.php');
        $this->assertStringContainsString('#33217', $jit);
        $this->assertStringContainsString('StringDateTime::ensureLinked', $jit);
    }

    public function testStringBuiltinStillImplementsFormatDatetimeOnFullLoad(): void
    {
        $string = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/String_.php');
        $this->assertStringContainsString('StringDateTime::implement($this->context)', $string);
    }

    public function testNoNewRuntimeCForFormatDatetimeAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/format_datetime.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/format_datetime.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_format_datetime.c');
    }
}
