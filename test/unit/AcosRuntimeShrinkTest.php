<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\AcosJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** acos() JIT: always AcosJitHelper via JitVmHelperLink + phpc_acos_kernel (#15141, #27048). */
final class AcosRuntimeShrinkTest extends TestCase
{
    public function testAcosUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/acos.php');
        $this->assertStringContainsString('MathAcos::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('acos')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathAcos.php');
        $this->assertStringContainsString('AcosJitHelper', $bridge);
        $this->assertStringContainsString('phpc_acos', $bridge);
        $this->assertStringContainsString('JitAcosKernel', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $bridge);
    }

    public function testAcosJitHelperDelegatesToKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/AcosJitHelper.php');
        $this->assertStringContainsString('phpc_acos_kernel', $source);
        $this->assertMatchesRegularExpression(
            '/function acosArgv\(.*?\{[^}]*phpc_acos_kernel/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function acosArgv\(.*?\{[^}]*VmMath::acos/s',
            $source
        );

        if (!\function_exists('phpc_acos_kernel')) {
            $this->markTestSkipped('phpc_acos_kernel requires compiler runtime');
        }
        $this->assertSame(
            VmMath::acos(1.0),
            AcosJitHelper::acosArgv(1.0)
        );
        $this->assertSame(
            VmMath::acos(0.5),
            AcosJitHelper::acosArgv(0.5)
        );
    }

    public function testContextAllowlistsAcosKernelForNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('phpc_acos_kernel', $source);
        $this->assertStringContainsString('phpc_fpow_kernel', $source);
    }

    public function testSpineBundleIncludesAcosJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('AcosJitHelper.php', $spine);
        $this->assertStringContainsString('MathAcos.php', $spine);
        $this->assertStringContainsString('JitAcosKernel.php', $spine);
        $this->assertStringContainsString('phpc_acos_kernel.php', $spine);
    }
}
