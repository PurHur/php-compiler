<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ExpJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** exp() JIT: always ExpJitHelper via JitVmHelperLink + phpc_exp_kernel (#15116, #27047). */
final class ExpRuntimeShrinkTest extends TestCase
{
    public function testExpUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/exp.php');
        $this->assertStringContainsString('MathExp::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('exp')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathExp.php');
        $this->assertStringContainsString('ExpJitHelper', $bridge);
        $this->assertStringContainsString('phpc_exp', $bridge);
        $this->assertStringContainsString('JitExpKernel', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $bridge);
    }

    public function testExpJitHelperDelegatesToKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ExpJitHelper.php');
        $this->assertStringContainsString('phpc_exp_kernel', $source);
        $this->assertMatchesRegularExpression(
            '/function expArgv\(.*?\{[^}]*phpc_exp_kernel/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function expArgv\(.*?\{[^}]*VmMath::exp/s',
            $source
        );

        if (!\function_exists('phpc_exp_kernel')) {
            $this->markTestSkipped('phpc_exp_kernel requires compiler runtime');
        }
        $this->assertSame(
            VmMath::exp(0.0),
            ExpJitHelper::expArgv(0.0)
        );
        $this->assertSame(
            VmMath::exp(1.0),
            ExpJitHelper::expArgv(1.0)
        );
    }

    public function testContextAllowlistsExpKernelForNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('phpc_exp_kernel', $source);
        $this->assertStringContainsString('phpc_hypot_kernel', $source);
    }

    public function testSpineBundleIncludesExpJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ExpJitHelper.php', $spine);
        $this->assertStringContainsString('MathExp.php', $spine);
        $this->assertStringContainsString('JitExpKernel.php', $spine);
        $this->assertStringContainsString('phpc_exp_kernel.php', $spine);
    }
}
