<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\CosJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** cos() JIT: always CosJitHelper via JitVmHelperLink + phpc_cos_kernel (#15087, #27005). */
final class CosRuntimeShrinkTest extends TestCase
{
    public function testCosUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/cos.php');
        $this->assertStringContainsString('MathCos::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('cos')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathCos.php');
        $this->assertStringContainsString('CosJitHelper', $bridge);
        $this->assertStringContainsString('phpc_cos', $bridge);
        $this->assertStringContainsString('JitCosKernel', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $bridge);
    }

    public function testCosJitHelperDelegatesToKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/CosJitHelper.php');
        $this->assertStringContainsString('phpc_cos_kernel', $source);
        $this->assertMatchesRegularExpression(
            '/function cosArgv\(.*?\{[^}]*phpc_cos_kernel/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function cosArgv\(.*?\{[^}]*VmMath::cos/s',
            $source
        );

        if (!\function_exists('phpc_cos_kernel')) {
            $this->markTestSkipped('phpc_cos_kernel requires compiler runtime');
        }
        $this->assertSame(
            VmMath::cos(0.0),
            CosJitHelper::cosArgv(0.0)
        );
        $this->assertSame(
            VmMath::cos(\deg2rad(60.0)),
            CosJitHelper::cosArgv(\deg2rad(60.0))
        );
    }

    public function testContextAllowlistsCosKernelForNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('phpc_cos_kernel', $source);
        $this->assertStringContainsString('phpc_sin_kernel', $source);
    }

    public function testSpineBundleIncludesCosJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('CosJitHelper.php', $spine);
        $this->assertStringContainsString('MathCos.php', $spine);
        $this->assertStringContainsString('JitCosKernel.php', $spine);
        $this->assertStringContainsString('phpc_cos_kernel.php', $spine);
    }
}
