<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FmodJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** fmod() JIT: always FmodJitHelper via JitVmHelperLink + phpc_fmod_kernel (#15072, #26994). */
final class FmodRuntimeShrinkTest extends TestCase
{
    public function testFmodUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/fmod.php');
        $this->assertStringContainsString('MathFmod::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('fmod')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathFmod.php');
        $this->assertStringContainsString('FmodJitHelper', $bridge);
        $this->assertStringContainsString('phpc_fmod', $bridge);
        $this->assertStringContainsString('JitFmodKernel', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $bridge);
    }

    public function testFmodJitHelperDelegatesToKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/FmodJitHelper.php');
        $this->assertStringContainsString('phpc_fmod_kernel', $source);
        $this->assertMatchesRegularExpression(
            '/function fmodArgv\(.*?\{[^}]*phpc_fmod_kernel/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function fmodArgv\(.*?\{[^}]*VmMath::fmod/s',
            $source
        );

        if (!\function_exists('phpc_fmod_kernel')) {
            $this->markTestSkipped('phpc_fmod_kernel requires compiler runtime');
        }
        $this->assertSame(
            VmMath::fmod(5.5, 2.0),
            FmodJitHelper::fmodArgv(5.5, 2.0)
        );
        $this->assertSame(
            VmMath::fmod(-1.5, 1.2),
            FmodJitHelper::fmodArgv(-1.5, 1.2)
        );
        $this->assertSame(
            VmMath::fmod(5.7, 1.3),
            FmodJitHelper::fmodArgv(5.7, 1.3)
        );
    }

    public function testContextAllowlistsFmodKernelForNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('phpc_fmod_kernel', $source);
        $this->assertStringContainsString('phpc_hypot_kernel', $source);
    }

    public function testSpineBundleIncludesFmodJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FmodJitHelper.php', $spine);
        $this->assertStringContainsString('MathFmod.php', $spine);
        $this->assertStringContainsString('JitFmodKernel.php', $spine);
        $this->assertStringContainsString('phpc_fmod_kernel.php', $spine);
    }
}
