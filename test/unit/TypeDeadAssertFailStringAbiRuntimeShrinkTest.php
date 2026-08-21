<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_assert_fail_string ABI shell from Builtin\Type (#33241).
 *
 * NestedJIT/AOT bridge stays AssertFail / JitAssert (php-src ext/standard/assert.c).
 * Runtime owner declares module-locally (getNamedFunction first, then addFunction if absent)
 * so leftover Type empty decls cannot mint assert_fail_string.1 (#31894 / #32122).
 */
final class TypeDeadAssertFailStringAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnAssertFailStringAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33241', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_assert_fail_string[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_assert_fail_string (#33241)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_assert_fail_string'",
            $type,
            'Builtin\\Type must not always-register __compiler_assert_fail_string (#33241)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (session_start_apply still Type always-on; #33258 stream_path dropped).
        $this->assertStringContainsString("registerFunction('__phpc_session_start_apply'", $type);
        $this->assertStringContainsString('AssertFail::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresAssertFailStringAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/AssertFail.php');
        $this->assertStringContainsString('#33241', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('declareAssertFailStringAbi', $owner);
        $this->assertStringContainsString('__compiler_assert_fail_string', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitAssert.php');
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitAssert.php');
        $this->assertStringContainsString('#33241', $jit);
        $this->assertStringContainsString('AssertFail::ensureLinked', $jit);
    }

    public function testTypeInitializeStillEnsureLinksAssertFail(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('AssertFail::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForAssertFailStringAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/assert_fail_string.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/assert_fail_string.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/compiler_assert_fail_string.c');
    }
}
