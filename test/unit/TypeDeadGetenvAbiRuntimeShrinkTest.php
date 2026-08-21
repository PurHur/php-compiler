<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on getenv ABI shells from Builtin\Type (#32665).
 *
 * User-script getenv()/putenv() stay PHP helpers.
 * Runtime owners declare module-locally (getNamedFunction first) so leftover Type
 * addFunction cannot mint name.1 (#31894 / #32122).
 */
final class TypeDeadGetenvAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_getenv',
            '__compiler_getenv_all',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnGetenvAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32665', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringNotContainsString(
                "addFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32665)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32665)"
            );
        }
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
        $this->assertStringContainsString("registerFunction('__compiler_proc_close'", $type);
    }

    public function testRuntimeOwnersDeclareAbisModuleLocally(): void
    {
        $getenv = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetenv.php');
        $this->assertStringContainsString('__compiler_getenv', $getenv);
        $this->assertStringContainsString('getNamedFunction(self::ABI_NAME)', $getenv);
        $this->assertStringContainsString('#32665', $getenv);

        $getenvAll = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetenvAll.php');
        $this->assertStringContainsString('__compiler_getenv_all', $getenvAll);
        $this->assertStringContainsString("getNamedFunction('__compiler_getenv_all')", $getenvAll);
        $this->assertStringContainsString('#32665', $getenvAll);
    }

    public function testNestedJitConsumersEnsureRuntimeBeforeLookup(): void
    {
        $env = (string) file_get_contents(__DIR__.'/../../ext/standard/JitEnv.php');
        $this->assertStringContainsString('StringGetenv::ensureLinked', $env);
        $this->assertStringContainsString('StringGetenvAll::ensureLinked', $env);
        $this->assertStringContainsString('#32665', $env);
        $this->assertStringContainsString("lookupFunction('__compiler_getenv')", $env);
        $this->assertStringContainsString("lookupFunction('__compiler_getenv_all')", $env);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertStringContainsString(
            'GetenvLookupJitHelper',
            (string) file_get_contents(__DIR__.'/../../ext/standard/GetenvLookupJitHelper.php')
        );
        $this->assertStringContainsString(
            'PutenvJitHelper',
            (string) file_get_contents(__DIR__.'/../../ext/standard/PutenvJitHelper.php')
        );
        $this->assertStringContainsString(
            'function getenv',
            (string) file_get_contents(__DIR__.'/../../ext/standard/VmEnv.php')
        );
    }
}
