<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\MicrotimeJitHelper;
use PHPCompiler\ext\standard\VmDate;
use PHPUnit\Framework\TestCase;

/**
 * microtime() AOT via MicrotimeJitHelper PHP + NestedJIT gettimeofday leaf (#29405).
 */
final class MicrotimeRuntimeShrinkTest extends TestCase
{
    public function testMicrotimeRoutesThroughJitVmHelperLink(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringMicrotime.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringContainsString('MicrotimeJitHelper', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringContainsString('JitMicrotimeKernel::invokeFloat', $bridge);
        $this->assertStringContainsString('JitMicrotimeKernel::invokeString', $bridge);
        $this->assertStringContainsString('__compiler_microtime_float', $bridge);
        $this->assertStringContainsString('__compiler_microtime_string', $bridge);
        $this->assertStringNotContainsString("lookupFunction('gettimeofday')", $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
        $this->assertLessThan(150, \substr_count($bridge, "\n") + 1);
    }

    public function testNestedLeafUsesLibcGettimeofday(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitMicrotimeKernel.php');
        $this->assertStringContainsString("lookupFunction('gettimeofday')", $source);
        $this->assertStringContainsString('__phpc_microtime_wall_usec', $source);
        $this->assertStringContainsString('tryGetInsertBlock', $source);
    }

    public function testMicrotimeJitHelperUsesHostMicrotimeNotVmDate(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/MicrotimeJitHelper.php');
        $this->assertStringContainsString('@\\microtime', $source);
        $this->assertStringNotContainsString('VmDate::microtime', $source);

        $float = MicrotimeJitHelper::microtimeFloat();
        $this->assertIsFloat($float);
        $this->assertGreaterThan(946684800.0, $float);
        $this->assertEqualsWithDelta(VmDate::microtime(true), $float, 1.0);

        $string = MicrotimeJitHelper::microtimeString();
        $parts = explode(' ', $string);
        $this->assertCount(2, $parts);
        $this->assertTrue(is_numeric($parts[0]));
        $this->assertTrue(is_numeric($parts[1]));
    }

    public function testNestedJitAllowlistsMicrotimeBuiltin(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'microtime'", $source);
        $this->assertStringContainsString('#29405', $source);
        $this->assertStringContainsString('isPreRegisterModuleNestedJitKernel', $source);
    }

    public function testSpineBundleIncludesMicrotimeArtifacts(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('MicrotimeJitHelper.php', $spine);
        $this->assertStringContainsString('StringMicrotime.php', $spine);
        $this->assertStringContainsString('JitMicrotimeKernel.php', $spine);
    }
}
