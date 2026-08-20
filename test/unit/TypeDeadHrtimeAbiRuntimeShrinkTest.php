<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on hrtime ABI shells from Builtin\Type (#32712).
 *
 * User-script hrtime() stays PHP helpers.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * addFunction cannot mint name.1 (#31894 / #32122).
 */
final class TypeDeadHrtimeAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_hrtime_ns',
            '__compiler_hrtime_pair',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnHrtimeAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32712', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringNotContainsString(
                "addFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32712)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32712)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_strtr'", $type);
        $this->assertStringNotContainsString('use PHPCompiler\\CompilerVersion;', $type);
    }

    public function testRuntimeOwnerDeclaresHrtimeAbisModuleLocally(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHrtimeRuntime.php');
        $this->assertStringContainsString('__compiler_hrtime_ns', $runtime);
        $this->assertStringContainsString('__compiler_hrtime_pair', $runtime);
        $this->assertStringContainsString('getNamedFunction($abiName)', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $runtime);
        $this->assertStringContainsString('#32712', $runtime);
        $this->assertStringContainsString('addFunction(', $runtime);
    }

    public function testNestedJitConsumersEnsureRuntimeBeforeLookup(): void
    {
        $date = (string) file_get_contents(__DIR__.'/../../ext/standard/JitDate.php');
        $this->assertStringContainsString('StringHrtime::ensureLinked', $date);
        $this->assertStringContainsString('#32712', $date);
        $this->assertStringContainsString("lookupFunction('__compiler_hrtime_ns')", $date);
        $this->assertStringContainsString("lookupFunction('__compiler_hrtime_pair')", $date);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertStringContainsString(
            'VmHrtimeNative::readMonotonic',
            (string) file_get_contents(__DIR__.'/../../ext/standard/HrtimeJitHelper.php')
        );
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_hrtime.c'
        );
    }
}
