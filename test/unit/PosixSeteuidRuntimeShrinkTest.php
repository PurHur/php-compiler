<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\posix\PosixSeteuidJitHelper;
use PHPCompiler\ext\posix\VmPosix;
use PHPUnit\Framework\TestCase;

/**
 * posix_seteuid() AOT via PosixSeteuidJitHelper PHP + NestedJIT libc seteuid(2) leaf (#31066).
 */
final class PosixSeteuidRuntimeShrinkTest extends TestCase
{
    public function testJitPosixSeteuidRoutesThroughPosixSeteuidJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/JitPosix.php');
        $this->assertStringContainsString('PosixSeteuidJit::invoke', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function seteuid\(Context \$context, Value \$uidI64\): Value\s*\{[^}]*lookupFunction\(\'seteuid\'\)/s',
            $source
        );
    }

    public function testPosixSeteuidJitRoutesThroughJitVmHelperLink(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PosixSeteuidJit.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringContainsString('PosixSeteuidJitHelper', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringContainsString('JitPosixSeteuidKernel::invoke', $bridge);
        $this->assertStringContainsString('__compiler_posix_seteuid', $bridge);
        $this->assertStringNotContainsString("lookupFunction('seteuid')", $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testNestedLeafOwnsLibcSeteuid(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/JitPosixSeteuidKernel.php');
        $this->assertStringContainsString("lookupFunction('seteuid')", $source);
        $this->assertStringContainsString('ensureLibcSeteuid', $source);
    }

    public function testPosixSeteuidJitHelperUsesHostPosixSeteuidNotVmPosix(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/PosixSeteuidJitHelper.php');
        $this->assertStringContainsString('@\\posix_seteuid', $source);
        $this->assertStringNotContainsString('VmPosix::seteuid', $source);

        if (!\function_exists('posix_seteuid') || !\function_exists('posix_geteuid')) {
            $this->markTestSkipped('host posix_seteuid unavailable');
        }
        $uid = (int) \posix_geteuid();
        $got = PosixSeteuidJitHelper::seteuidArgv($uid);
        $this->assertSame(1, $got);
        $this->assertTrue(VmPosix::seteuid($uid));
        $this->assertTrue((bool) \posix_seteuid($uid));
    }

    public function testNestedJitAllowlistsPosixSeteuidBuiltin(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'posix_seteuid'", $source);
        $this->assertStringContainsString('#31066', $source);
    }

    public function testSpineBundleIncludesPosixSeteuidArtifacts(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('PosixSeteuidJitHelper.php', $spine);
        $this->assertStringContainsString('PosixSeteuidJit.php', $spine);
        $this->assertStringContainsString('JitPosixSeteuidKernel.php', $spine);
    }

    public function testPosixSeteuidBuiltinRoutesThroughJitPosix(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/posix_seteuid.php');
        $this->assertStringContainsString('JitPosix::seteuid', $source);
        $this->assertStringContainsString('JitLongArg::lower', $source);
        $this->assertStringContainsString('#31066', $source);
    }

    public function testTypeRegistersPosixSeteuidBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('PosixSeteuidJit::ensureLinked', $source);
    }
}
