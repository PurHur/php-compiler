<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on microtime/gettimeofday ABI shells from Builtin\Type (#32683).
 *
 * User-script microtime()/gettimeofday() stay PHP helpers.
 * Runtime owners declare module-locally (getNamedFunction first) so leftover Type
 * addFunction cannot mint name.1 (#31894 / #32122).
 */
final class TypeDeadMicrotimeGettimeofdayAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_microtime_string',
            '__compiler_microtime_float',
            '__compiler_gettimeofday_array',
            '__compiler_gettimeofday_float',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnMicrotimeGettimeofdayAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32683', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringNotContainsString(
                "addFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32683)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32683)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_popen'", $type);
    }

    public function testRuntimeOwnersDeclareAbisModuleLocally(): void
    {
        $microtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringMicrotime.php');
        $this->assertStringContainsString('__compiler_microtime_string', $microtime);
        $this->assertStringContainsString('__compiler_microtime_float', $microtime);
        $this->assertStringContainsString('getNamedFunction(self::ABI_STRING)', $microtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $microtime);

        $gettimeofday = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGettimeofday.php');
        $this->assertStringContainsString('__compiler_gettimeofday_array', $gettimeofday);
        $this->assertStringContainsString('__compiler_gettimeofday_float', $gettimeofday);
        $this->assertStringContainsString("getNamedFunction('__compiler_gettimeofday_array')", $gettimeofday);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $gettimeofday);
    }

    public function testNestedJitConsumersEnsureRuntimeBeforeLookup(): void
    {
        $date = (string) file_get_contents(__DIR__.'/../../ext/standard/JitDate.php');
        $this->assertStringContainsString('StringMicrotime::ensureLinked', $date);
        $this->assertStringContainsString('#32683', $date);
        $this->assertStringContainsString('StringMicrotime::invokeFloat', $date);
        $this->assertStringContainsString('StringMicrotime::invokeString', $date);

        $gettimeofday = (string) file_get_contents(__DIR__.'/../../ext/standard/JitGettimeofday.php');
        $this->assertStringContainsString('StringGettimeofday::ensureLinked', $gettimeofday);
        $this->assertStringContainsString('#32683', $gettimeofday);
        $this->assertStringContainsString("lookupFunction('__hashtable__alloc')", $gettimeofday);
        $this->assertStringContainsString('DefaultTimezoneCivilRuntime::ensureLinked', $gettimeofday);
        $this->assertStringContainsString('StringMicrotime::invokeFloat', $gettimeofday);
        $this->assertStringContainsString('JitDate::time', $gettimeofday);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertStringContainsString(
            'MicrotimeJitHelper',
            (string) file_get_contents(__DIR__.'/../../ext/standard/MicrotimeJitHelper.php')
        );
        $this->assertStringContainsString(
            'GettimeofdayJitHelper',
            (string) file_get_contents(__DIR__.'/../../ext/standard/GettimeofdayJitHelper.php')
        );
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_microtime.c'
        );
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_gettimeofday.c'
        );
    }
}
