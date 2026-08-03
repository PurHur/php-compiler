<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\AsinJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** asin() JIT: always AsinJitHelper via JitVmHelperLink + phpc_asin_kernel (#15130, #27016). */
final class AsinRuntimeShrinkTest extends TestCase
{
    public function testAsinUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/asin.php');
        $this->assertStringContainsString('MathAsin::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('asin')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathAsin.php');
        $this->assertStringContainsString('AsinJitHelper', $bridge);
        $this->assertStringContainsString('phpc_asin', $bridge);
        $this->assertStringContainsString('JitAsinKernel', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $bridge);
    }

    public function testAsinJitHelperDelegatesToKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/AsinJitHelper.php');
        $this->assertStringContainsString('phpc_asin_kernel', $source);
        $this->assertMatchesRegularExpression(
            '/function asinArgv\(.*?\{[^}]*phpc_asin_kernel/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function asinArgv\(.*?\{[^}]*VmMath::asin/s',
            $source
        );

        if (!\function_exists('phpc_asin_kernel')) {
            $this->markTestSkipped('phpc_asin_kernel requires compiler runtime');
        }
        $this->assertSame(
            VmMath::asin(0.0),
            AsinJitHelper::asinArgv(0.0)
        );
        $this->assertSame(
            VmMath::asin(0.5),
            AsinJitHelper::asinArgv(0.5)
        );
    }

    public function testContextAllowlistsAsinKernelForNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('phpc_asin_kernel', $source);
        $this->assertStringContainsString('phpc_acos_kernel', $source);
    }

    public function testSpineBundleIncludesAsinJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('AsinJitHelper.php', $spine);
        $this->assertStringContainsString('MathAsin.php', $spine);
        $this->assertStringContainsString('JitAsinKernel.php', $spine);
        $this->assertStringContainsString('phpc_asin_kernel.php', $spine);
    }
}
