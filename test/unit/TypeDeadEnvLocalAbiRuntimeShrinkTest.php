<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on env_local/putenv ABI shells from Builtin\Type (#32729).
 *
 * User-script getenv()/putenv() stay PHP helpers.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint name.1 (#31894 / #32122).
 */
final class TypeDeadEnvLocalAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_env_local_lookup',
            '__compiler_env_register_putenv',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnEnvLocalAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32729', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringNotContainsString(
                "addFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32729)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32729)"
            );
            $this->assertStringNotContainsString(
                "'{$sym}' =>",
                $type,
                "Builtin\\Type must not always-declare {$sym} in a table (#32729)"
            );
        }
        $this->assertStringNotContainsString('function ensureExternalFunction', $type);
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_popen'", $type);
        $this->assertStringContainsString('EnvLocalRuntime::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresEnvLocalAbisModuleLocally(): void
    {
        $kernel = (string) file_get_contents(__DIR__.'/../../ext/standard/JitEnvLocalKernel.php');
        $this->assertStringContainsString('__compiler_env_local_lookup', $kernel);
        $this->assertStringContainsString('__compiler_env_register_putenv', $kernel);
        $this->assertStringContainsString("getNamedFunction('__compiler_env_local_lookup')", $kernel);
        $this->assertStringContainsString("getNamedFunction('__compiler_env_register_putenv')", $kernel);
        $this->assertStringContainsString('#32729', $kernel);

        $orch = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/EnvLocalRuntime.php');
        $this->assertStringContainsString('JitEnvLocalKernel::ensureLinked', $orch);
        $this->assertStringContainsString('#32729', $orch);
    }

    public function testTypeRegisterStillEnsureLinksEnvLocalRuntime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('EnvLocalRuntime::ensureLinked($this->context)', $type);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertStringContainsString(
            'EnvLocalJitHelper',
            (string) file_get_contents(__DIR__.'/../../ext/standard/EnvLocalJitHelper.php')
        );
        $this->assertStringContainsString(
            'GetenvLookupJitHelper',
            (string) file_get_contents(__DIR__.'/../../ext/standard/GetenvLookupJitHelper.php')
        );
        $this->assertStringContainsString(
            'PutenvJitHelper',
            (string) file_get_contents(__DIR__.'/../../ext/standard/PutenvJitHelper.php')
        );
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_env_local.c'
        );
    }
}
