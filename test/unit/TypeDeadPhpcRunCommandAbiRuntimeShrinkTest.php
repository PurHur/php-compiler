<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_phpc_run_command ABI shell from Builtin\Type (#33212).
 *
 * NestedJIT/AOT bridge stays ProcessRuntime / JitPhpcRunCommand.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint phpc_run_command.1 (#31894 / #32122).
 */
final class TypeDeadPhpcRunCommandAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnPhpcRunCommandAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33212', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_phpc_run_command[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_phpc_run_command (#33212)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_phpc_run_command'",
            $type,
            'Builtin\\Type must not always-register __compiler_phpc_run_command (#33212)'
        );
        // ProcessRuntime ensureLinked moved to call-site (#34333); phpc_run_command
        // links via ensurePhpcRunCommandLinked from JitPhpcRunCommand.
        $this->assertStringContainsString('#34333', $type);
        $this->assertStringNotContainsString('ProcessRuntime::ensureLinked($this->context)', $type);
    }

    public function testRuntimeOwnerDeclaresPhpcRunCommandAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ProcessRuntime.php');
        $this->assertStringContainsString('#33212', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('ensurePhpcRunCommandLinked', $owner);
        $this->assertStringContainsString('__compiler_phpc_run_command', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/ProcessPhpcRunCommandJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitPhpcRunCommand.php');
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitPhpcRunCommand.php');
        $this->assertStringContainsString('#33212', $jit);
        $this->assertStringContainsString('ensurePhpcRunCommandLinked', $jit);
    }

    public function testNoNewRuntimeCForPhpcRunCommandAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_run_command.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/phpc_run_command.c');
    }
}
