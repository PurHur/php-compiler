<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\Deg2radJitHelper;
use PHPCompiler\ext\standard\Rad2degJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** deg2rad()/rad2deg() JIT routes through JitHelper PHP not inline LLVM fMul (#15143). */
final class Deg2radRuntimeShrinkTest extends TestCase
{
    public function testDeg2radUsesJitHelperNotInlineFmul(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/deg2rad.php');
        $this->assertStringContainsString('MathDeg2rad::invoke', $builtin);
        $this->assertStringNotContainsString('fMul', $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathDeg2rad.php');
        $this->assertStringContainsString('Deg2radJitHelper', $bridge);
        $this->assertStringContainsString('phpc_deg2rad', $bridge);
    }

    public function testRad2degUsesJitHelperNotInlineFmul(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/rad2deg.php');
        $this->assertStringContainsString('MathRad2deg::invoke', $builtin);
        $this->assertStringNotContainsString('fMul', $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathRad2deg.php');
        $this->assertStringContainsString('Rad2degJitHelper', $bridge);
        $this->assertStringContainsString('phpc_rad2deg', $bridge);
    }

    public function testJitHelpersDelegateToVmMath(): void
    {
        $this->assertSame(
            VmMath::deg2rad(180.0),
            Deg2radJitHelper::deg2radArgv(180.0)
        );
        $this->assertSame(
            VmMath::rad2deg(\M_PI),
            Rad2degJitHelper::rad2degArgv(\M_PI)
        );
    }

    public function testSpineBundleIncludesDeg2radHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('Deg2radJitHelper.php', $spine);
        $this->assertStringContainsString('Rad2degJitHelper.php', $spine);
        $this->assertStringContainsString('MathDeg2rad.php', $spine);
        $this->assertStringContainsString('MathRad2deg.php', $spine);
    }
}
