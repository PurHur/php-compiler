<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on time_nanosleep/time_sleep_until ABI shells from Builtin\Type (#32721).
 *
 * User-script time_nanosleep()/time_sleep_until() stay PHP helpers.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * addFunction cannot mint name.1 (#31894 / #32122).
 */
final class TypeDeadTimeSleepAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_time_nanosleep',
            '__compiler_time_sleep_until',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnTimeSleepAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32721', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringNotContainsString(
                "addFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32721)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32721)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_proc_open'", $type);
    }

    public function testRuntimeOwnerDeclaresTimeSleepAbisModuleLocally(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/TimeSleepRuntime.php');
        $this->assertStringContainsString('__compiler_time_nanosleep', $runtime);
        $this->assertStringContainsString('__compiler_time_sleep_until', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $runtime);
        $this->assertStringContainsString('#32721', $runtime);
    }

    public function testNestedJitConsumersEnsureRuntimeBeforeLookup(): void
    {
        $sleep = (string) file_get_contents(__DIR__.'/../../ext/standard/JitSleep.php');
        $this->assertStringContainsString('TimeSleepRuntime::ensureLinked', $sleep);
        $this->assertStringContainsString('#32721', $sleep);
        $this->assertStringContainsString("lookupFunction('__compiler_time_nanosleep')", $sleep);
        $this->assertStringContainsString("lookupFunction('__compiler_time_sleep_until')", $sleep);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertStringContainsString(
            'VmSleepPure::timeNanosleep',
            (string) file_get_contents(__DIR__.'/../../ext/standard/SleepJitHelper.php')
        );
        $this->assertStringContainsString(
            'VmSleepPure::timeSleepUntil',
            (string) file_get_contents(__DIR__.'/../../ext/standard/SleepJitHelper.php')
        );
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_time_sleep.c'
        );
    }
}
