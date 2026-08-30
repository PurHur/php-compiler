<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_assert_fail ABI shell from Builtin\Type (#33237).
 *
 * NestedJIT/AOT bridge stays AssertFail / JitAssert (php-src ext/standard/assert.c).
 * Runtime owner declares module-locally (getNamedFunction first, then addFunction if absent)
 * so leftover Type empty decls cannot mint assert_fail.1 (#31894 / #32122).
 */
final class TypeDeadAssertFailAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnAssertFailAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33237', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_assert_fail[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_assert_fail (#33237)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_assert_fail'",
            $type,
            'Builtin\\Type must not always-register __compiler_assert_fail (#33237)'
        );
        // No further Type always-on leftover after #33267 exit/abort drop;
        // AssertFail ensureLinked moved to JitAssert call site (#34463).
        $this->assertStringContainsString('#34463', $type);
    }

    public function testRuntimeOwnerDeclaresAssertFailAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/AssertFail.php');
        $this->assertStringContainsString('#33237', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('declareAssertFailAbi', $owner);
        $this->assertStringContainsString('__compiler_assert_fail', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitAssert.php');
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitAssert.php');
        $this->assertStringContainsString('#33237', $jit);
        $this->assertStringContainsString('AssertFail::ensureLinked', $jit);
    }

    public function testTypeInitializeNoLongerEagerLinksAssertFail(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $initPos = strpos($type, 'public function initialize(): void');
        $this->assertNotFalse($initPos);
        $initBody = substr($type, $initPos);
        $this->assertDoesNotMatchRegularExpression(
            '/AssertFail::ensureLinked\\(\\$this->context\\)/',
            $initBody,
            'Type::initialize must not eagerly AssertFail::ensureLinked (#34463)'
        );
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitAssert.php');
        $this->assertStringContainsString('AssertFail::ensureLinked($context)', $jit);
    }

    public function testNoNewRuntimeCForAssertFailAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/assert_fail.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/assert_fail.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/compiler_assert_fail.c');
    }
}
