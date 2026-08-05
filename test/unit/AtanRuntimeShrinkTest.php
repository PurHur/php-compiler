<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\AtanJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** atan() JIT: always AtanJitHelper via JitVmHelperLink + phpc_atan_kernel (#15142, #27017). */
final class AtanRuntimeShrinkTest extends TestCase
{
    public function testAtanUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/atan.php');
        $this->assertStringContainsString('MathAtan::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('atan')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathAtan.php');
        $this->assertStringContainsString('AtanJitHelper', $bridge);
        $this->assertStringContainsString('phpc_atan', $bridge);
        $this->assertStringContainsString('JitAtanKernel', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $bridge);
    }

    public function testAtanJitHelperDelegatesToKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/AtanJitHelper.php');
        $this->assertStringContainsString('phpc_atan_kernel', $source);
        $this->assertMatchesRegularExpression(
            '/function atanArgv\(.*?\{[^}]*phpc_atan_kernel/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function atanArgv\(.*?\{[^}]*VmMath::atan/s',
            $source
        );

        if (!\function_exists('phpc_atan_kernel')) {
            $this->markTestSkipped('phpc_atan_kernel requires compiler runtime');
        }
        $this->assertSame(
            VmMath::atan(0.0),
            AtanJitHelper::atanArgv(0.0)
        );
        $this->assertSame(
            VmMath::atan(1.0),
            AtanJitHelper::atanArgv(1.0)
        );
    }

    public function testContextAllowlistsAtanKernelForNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('phpc_atan_kernel', $source);
        $this->assertStringContainsString('phpc_atan2_kernel', $source);
    }

    public function testSpineBundleIncludesAtanJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('AtanJitHelper.php', $spine);
        $this->assertStringContainsString('MathAtan.php', $spine);
        $this->assertStringContainsString('JitAtanKernel.php', $spine);
        $this->assertStringContainsString('phpc_atan_kernel.php', $spine);
    }
}
