<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_assert_options ABI shell from Builtin\Type (#33245).
 *
 * NestedJIT/AOT bridge stays AssertOptionsRuntime / JitAssertOptions
 * (php-src ext/standard/assert.c). Runtime owner declares module-locally
 * (getNamedFunction first, then addFunction if absent) so leftover Type empty
 * decls cannot mint assert_options.1 (#31894 / #32122).
 */
final class TypeDeadAssertOptionsAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnAssertOptionsAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33245', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_assert_options[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_assert_options (#33245)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_assert_options'",
            $type,
            'Builtin\\Type must not always-register __compiler_assert_options (#33245)'
        );
        // No further Type always-on leftover after #33267 exit/abort drop.
        $this->assertStringContainsString('AssertOptionsRuntime::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresAssertOptionsAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/AssertOptionsRuntime.php');
        $this->assertStringContainsString('#33245', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('declareAssertOptionsAbi', $owner);
        $this->assertStringContainsString('__compiler_assert_options', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitAssertOptions.php');
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitAssertOptions.php');
        $this->assertStringContainsString('AssertOptionsRuntime::ensureLinked', $jit);
    }

    public function testTypeInitializeStillEnsureLinksAssertOptions(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('AssertOptionsRuntime::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForAssertOptionsAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/assert_options.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/assert_options.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/compiler_assert_options.c');
    }
}
