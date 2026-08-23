<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_is_superglobal_name ABI shell from Builtin\Type (#33235).
 *
 * NestedJIT/AOT bridge stays StringSuperglobalName / SuperglobalNameRuntime / JitSuperglobalName.
 * Runtime owner declares module-locally (getNamedFunction first, then addFunction if absent)
 * so leftover Type empty decls cannot mint is_superglobal_name.1 (#31894 / #32122).
 */
final class TypeDeadIsSuperglobalNameAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnIsSuperglobalNameAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33235', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_is_superglobal_name[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_is_superglobal_name (#33235)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_is_superglobal_name'",
            $type,
            'Builtin\\Type must not always-register __compiler_is_superglobal_name (#33235)'
        );
        // No further Type always-on leftover after #33267 exit/abort drop.
        $this->assertStringContainsString('LibcExtern::ensureExitAbort', $type);
        $this->assertStringContainsString('StringSuperglobalName::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresIsSuperglobalNameAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SuperglobalNameRuntime.php');
        $this->assertStringContainsString('#33235', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_is_superglobal_name', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/SuperglobalNameJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitSuperglobalName.php');
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitSuperglobalName.php');
        $this->assertStringContainsString('#33235', $jit);
        $this->assertStringContainsString('StringSuperglobalName::ensureLinked', $jit);
        $hook = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringSuperglobalName.php');
        $this->assertStringContainsString('#33235', $hook);
    }

    public function testTypeInitializeLazyLinksStringSuperglobalName(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#34243', $type);
        $this->assertStringNotContainsString(
            'StringSuperglobalName::ensureLinked($this->context)',
            $type,
            'Builtin\\Type::initialize must not eagerly StringSuperglobalName::ensureLinked (#34243)'
        );
        $jit = (string) file_get_contents(__DIR__.'/../../lib/JIT.php');
        $this->assertStringContainsString('StringSuperglobalName::ensureLinked', $jit);
    }

    public function testNoNewRuntimeCForIsSuperglobalNameAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/is_superglobal_name.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/is_superglobal_name.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/compiler_is_superglobal_name.c');
    }
}
