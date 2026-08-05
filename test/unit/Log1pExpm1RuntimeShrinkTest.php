<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\Expm1JitHelper;
use PHPCompiler\ext\standard\Log1pJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** log1p()/expm1() JIT: always JitHelper via JitVmHelperLink + phpc_*_kernel (#15157, #27057). */
final class Log1pExpm1RuntimeShrinkTest extends TestCase
{
    public function testLog1pUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/log1p.php');
        $this->assertStringContainsString('MathLog1p::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('log1p')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathLog1p.php');
        $this->assertStringContainsString('Log1pJitHelper', $bridge);
        $this->assertStringContainsString('phpc_log1p', $bridge);
        $this->assertStringContainsString('JitLog1pKernel', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
    }

    public function testExpm1UsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/expm1.php');
        $this->assertStringContainsString('MathExpm1::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('expm1')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathExpm1.php');
        $this->assertStringContainsString('Expm1JitHelper', $bridge);
        $this->assertStringContainsString('phpc_expm1', $bridge);
        $this->assertStringContainsString('JitExpm1Kernel', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
    }

    public function testJitHelpersDelegateToKernel(): void
    {
        $log1p = (string) file_get_contents(__DIR__.'/../../ext/standard/Log1pJitHelper.php');
        $this->assertStringContainsString('phpc_log1p_kernel', $log1p);
        $this->assertDoesNotMatchRegularExpression(
            '/function log1pArgv\(.*?\{[^}]*VmMath::log1p/s',
            $log1p
        );

        $expm1 = (string) file_get_contents(__DIR__.'/../../ext/standard/Expm1JitHelper.php');
        $this->assertStringContainsString('phpc_expm1_kernel', $expm1);
        $this->assertDoesNotMatchRegularExpression(
            '/function expm1Argv\(.*?\{[^}]*VmMath::expm1/s',
            $expm1
        );

        if (!\function_exists('phpc_log1p_kernel')) {
            $this->markTestSkipped('phpc_*_kernel requires compiler runtime');
        }
        $this->assertSame(VmMath::log1p(0.0), Log1pJitHelper::log1pArgv(0.0));
        $this->assertSame(VmMath::log1p(1.0), Log1pJitHelper::log1pArgv(1.0));
        $this->assertSame(VmMath::expm1(0.0), Expm1JitHelper::expm1Argv(0.0));
        $this->assertSame(VmMath::expm1(1.0), Expm1JitHelper::expm1Argv(1.0));
    }

    public function testContextAllowlistsLog1pExpm1KernelsForNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('phpc_log1p_kernel', $source);
        $this->assertStringContainsString('phpc_expm1_kernel', $source);
    }

    public function testSpineBundleIncludesLog1pExpm1JitHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        foreach ([
            'Log1pJitHelper.php', 'Expm1JitHelper.php',
            'MathLog1p.php', 'MathExpm1.php',
            'JitLog1pKernel.php', 'JitExpm1Kernel.php',
            'phpc_log1p_kernel.php', 'phpc_expm1_kernel.php',
        ] as $f) {
            $this->assertStringContainsString($f, $spine);
        }
    }
}
