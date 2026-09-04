<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\AbsJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * abs() AOT uses llvm.fabs.f64 + inline i64 select (#36386); AbsJitHelper remains
 * for VM / NestedJIT (peer MathSqrt / SqrtJitHelper).
 *
 * php-src: ext/standard/math.c PHP_FUNCTION(abs) → fabs / long negate.
 */
final class AbsRuntimeShrinkTest extends TestCase
{
    public function testAbsUsesLlvmIntrinsicNotHelperBridge(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/abs.php');
        $this->assertStringContainsString('MathAbs::invokeDouble', $builtin);
        $this->assertStringContainsString('MathAbs::invokeLong', $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathAbs.php');
        $this->assertStringContainsString('llvm.fabs.f64', $bridge);
        $this->assertStringContainsString('phpc_abs_double', $bridge);
        $this->assertStringContainsString('phpc_abs_long', $bridge);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('abs_double_bridge_entry', $bridge);
        $this->assertStringNotContainsString('abs_long_bridge_entry', $bridge);
        $this->assertStringNotContainsString('AbsJitHelper::', $bridge);
        $this->assertStringNotContainsString('absDoubleArgv', $bridge);
    }

    public function testAbsJitHelperDelegatesToVmSemantics(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/AbsJitHelper.php');
        $this->assertStringContainsString('absDoubleArgv', $source);
        $this->assertStringContainsString('absLongArgv', $source);

        $this->assertSame(3.5, AbsJitHelper::absDoubleArgv(-3.5));
        $this->assertSame(7, AbsJitHelper::absLongArgv(-7));
        $this->assertSame(0, AbsJitHelper::absLongArgv(0));
        // php-src fabs: signed zero → +0.0 (#23978)
        $this->assertSame('0.0', \var_export(AbsJitHelper::absDoubleArgv(-0.0), true));
        $this->assertSame('0', \json_encode(AbsJitHelper::absDoubleArgv(-0.0)));
    }

    public function testSpineBundleIncludesAbsJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('AbsJitHelper.php', $spine);
        $this->assertStringContainsString('MathAbs.php', $spine);
    }
}
