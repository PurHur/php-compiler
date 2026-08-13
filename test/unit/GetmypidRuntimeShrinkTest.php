<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\GetmypidJitHelper;
use PHPCompiler\ext\standard\VmDate;
use PHPUnit\Framework\TestCase;

/**
 * getmypid() AOT via GetmypidJitHelper PHP + NestedJIT libc getpid(2) leaf (#30623).
 */
final class GetmypidRuntimeShrinkTest extends TestCase
{
    public function testJitDateRoutesThroughProcessIdentityJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitDate.php');
        $this->assertStringContainsString('ProcessIdentityJit::getmypid', $source);
        $this->assertStringNotContainsString("lookupFunction('getpid')", $source);
    }

    public function testProcessIdentityJitRoutesThroughJitVmHelperLink(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ProcessIdentityJit.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringContainsString('GetmypidJitHelper', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringContainsString('JitGetmypidKernel::invoke', $bridge);
        $this->assertStringContainsString('__compiler_getmypid', $bridge);
        $this->assertStringNotContainsString("lookupFunction('getpid')", $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testNestedLeafUsesLibcGetpid(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitGetmypidKernel.php');
        $this->assertStringContainsString("lookupFunction('getpid')", $source);
        $this->assertStringContainsString('ensureLibcGetpid', $source);
    }

    public function testGetmypidJitHelperUsesHostGetmypidNotVmDate(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/GetmypidJitHelper.php');
        $this->assertStringContainsString('@\\getmypid', $source);
        $this->assertStringNotContainsString('VmDate::getmypid', $source);
        $this->assertStringNotContainsString('VmProcessIdentityNative::', $source);

        $got = GetmypidJitHelper::getmypidArgv();
        $this->assertIsInt($got);
        $this->assertGreaterThan(0, $got);
        $this->assertSame(VmDate::getmypid(), $got);
        $this->assertSame(\getmypid(), $got);
    }

    public function testNestedJitAllowlistsGetmypidBuiltin(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'getmypid'", $source);
        $this->assertStringContainsString('#30623', $source);
        $this->assertStringContainsString('isPreRegisterModuleNestedJitKernel', $source);
    }

    public function testSpineBundleIncludesGetmypidArtifacts(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('GetmypidJitHelper.php', $spine);
        $this->assertStringContainsString('JitGetmypidKernel.php', $spine);
        $this->assertStringContainsString('ProcessIdentityJit.php', $spine);
    }

    public function testGetmypidBuiltinRoutesThroughJitDate(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/getmypid.php');
        $this->assertStringContainsString('JitDate::getmypid', $source);
        $this->assertStringContainsString('#30623', $source);
    }
}
