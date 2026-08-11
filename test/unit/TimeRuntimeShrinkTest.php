<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\TimeJitHelper;
use PHPCompiler\ext\standard\VmDate;
use PHPUnit\Framework\TestCase;

/**
 * time() AOT via TimeJitHelper PHP + NestedJIT libc time(2) leaf (#30332).
 */
final class TimeRuntimeShrinkTest extends TestCase
{
    public function testJitDateRoutesThroughStringTime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitDate.php');
        $this->assertStringContainsString('StringTime::invoke', $source);
        $this->assertStringNotContainsString("lookupFunction('time')", $source);
    }

    public function testStringTimeRoutesThroughJitVmHelperLink(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringTime.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringContainsString('TimeJitHelper', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringContainsString('JitTimeKernel::invoke', $bridge);
        $this->assertStringContainsString('__compiler_time', $bridge);
        $this->assertStringNotContainsString("lookupFunction('time')", $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
        $this->assertLessThan(120, \substr_count($bridge, "\n") + 1);
    }

    public function testNestedLeafUsesLibcTime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitTimeKernel.php');
        $this->assertStringContainsString("lookupFunction('time')", $source);
        $this->assertStringContainsString('ensureLibcTime', $source);
    }

    public function testTimeJitHelperUsesHostTimeNotVmDate(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/TimeJitHelper.php');
        $this->assertStringContainsString('@\\time', $source);
        $this->assertStringNotContainsString('VmDate::time', $source);

        $got = TimeJitHelper::timeArgv();
        $this->assertIsInt($got);
        $this->assertGreaterThan(946684800, $got);
        $this->assertEqualsWithDelta(VmDate::time(), $got, 1);
        $this->assertEqualsWithDelta(\time(), $got, 1);
    }

    public function testLibcExternDropsTimeDecl(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringNotContainsString("'time' =>", $source);
        $this->assertStringContainsString('#30332', $source);
    }

    public function testNestedJitAllowlistsTimeBuiltin(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'time'", $source);
        $this->assertStringContainsString('#30332', $source);
        $this->assertStringContainsString('isPreRegisterModuleNestedJitKernel', $source);
    }

    public function testTypeLinksStringTime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringTime::ensureLinked', $source);
    }

    public function testSpineBundleIncludesTimeArtifacts(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('TimeJitHelper.php', $spine);
        $this->assertStringContainsString('StringTime.php', $spine);
        $this->assertStringContainsString('JitTimeKernel.php', $spine);
    }
}
