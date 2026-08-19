<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on stat/mktime/getrusage ABI shells from Builtin\Type (#32651).
 *
 * User-script stat()/lstat()/mktime()/getrusage() stay PHP helpers.
 * Runtime owners declare module-locally (getNamedFunction first) so leftover Type
 * addFunction cannot mint name.1 (#31894 / #32122).
 */
final class TypeDeadStatMktimeGetrusageAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__phpc_stat',
            '__compiler_mktime',
            '__compiler_getrusage',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnStatMktimeGetrusageAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32651', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringNotContainsString(
                "addFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32651)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32651)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__phpc_stream_path'", $type);
    }

    public function testRuntimeOwnersDeclareAbisModuleLocally(): void
    {
        $stat = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StatArrayRuntime.php');
        $this->assertStringContainsString('__phpc_stat', $stat);
        $this->assertStringContainsString("getNamedFunction('__phpc_stat')", $stat);

        $mktime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringMktime.php');
        $this->assertStringContainsString('__compiler_mktime', $mktime);
        $this->assertStringContainsString("getNamedFunction('__compiler_mktime')", $mktime);

        $getrusage = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetrusageRuntime.php');
        $this->assertStringContainsString('__compiler_getrusage', $getrusage);
        $this->assertStringContainsString("getNamedFunction(self::ABI_NAME)", $getrusage);
    }

    public function testNestedJitConsumersEnsureRuntimeBeforeLookup(): void
    {
        $stat = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStatArray.php');
        $this->assertStringContainsString('StatArrayRuntime::ensureLinked', $stat);
        $this->assertStringContainsString('#32651', $stat);
        $this->assertStringContainsString("lookupFunction('__phpc_stat')", $stat);

        $mktime = (string) file_get_contents(__DIR__.'/../../ext/standard/JitMktime.php');
        $this->assertStringContainsString('StringMktime::ensureLinked', $mktime);
        $this->assertStringContainsString("lookupFunction('__compiler_mktime')", $mktime);

        $getrusage = (string) file_get_contents(__DIR__.'/../../ext/standard/JitGetrusage.php');
        $this->assertStringContainsString('StringGetrusage::ensureLinked', $getrusage);
        $this->assertStringContainsString("lookupFunction('__compiler_getrusage')", $getrusage);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertStringContainsString(
            'StatArrayJitHelper',
            (string) file_get_contents(__DIR__.'/../../ext/standard/StatArrayJitHelper.php')
        );
        $this->assertStringContainsString(
            'MktimeJitHelper',
            (string) file_get_contents(__DIR__.'/../../ext/standard/MktimeJitHelper.php')
        );
        $this->assertStringContainsString(
            'GetrusageJitHelper',
            (string) file_get_contents(__DIR__.'/../../ext/standard/GetrusageJitHelper.php')
        );
    }
}
