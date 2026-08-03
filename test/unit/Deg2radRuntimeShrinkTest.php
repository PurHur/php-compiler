<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\Deg2radJitHelper;
use PHPCompiler\ext\standard\Rad2degJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * deg2rad()/rad2deg() NestedJIT via JitVmHelperLink::ensureBridge (#27400 / peer Frexp #22575).
 */
final class Deg2radRuntimeShrinkTest extends TestCase
{
    public function testDeg2radUsesJitHelperNotKernel(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/deg2rad.php');
        $this->assertStringContainsString('MathDeg2rad::invoke', $builtin);
        $this->assertStringNotContainsString('fMul', $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathDeg2rad.php');
        $this->assertStringContainsString('Deg2radJitHelper', $bridge);
        $this->assertStringContainsString('phpc_deg2rad', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('JitDeg2radKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testRad2degUsesJitHelperNotKernel(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/rad2deg.php');
        $this->assertStringContainsString('MathRad2deg::invoke', $builtin);
        $this->assertStringNotContainsString('fMul', $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathRad2deg.php');
        $this->assertStringContainsString('Rad2degJitHelper', $bridge);
        $this->assertStringContainsString('phpc_rad2deg', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('JitRad2degKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testJitHelpersInlineMultiplyMatchingVmMath(): void
    {
        $deg = (string) file_get_contents(__DIR__.'/../../ext/standard/Deg2radJitHelper.php');
        $this->assertStringContainsString('M_PI / 180.0', $deg);
        $this->assertDoesNotMatchRegularExpression(
            '/function deg2radArgv\(.*?\{[^}]*VmMath::/s',
            $deg
        );
        $this->assertStringNotContainsString('phpc_deg2rad_kernel', $deg);

        $rad = (string) file_get_contents(__DIR__.'/../../ext/standard/Rad2degJitHelper.php');
        $this->assertStringContainsString('180.0 / \\M_PI', $rad);
        $this->assertDoesNotMatchRegularExpression(
            '/function rad2degArgv\(.*?\{[^}]*VmMath::/s',
            $rad
        );
        $this->assertStringNotContainsString('phpc_rad2deg_kernel', $rad);

        $this->assertSame(VmMath::deg2rad(180.0), Deg2radJitHelper::deg2radArgv(180.0));
        $this->assertSame(VmMath::rad2deg(\M_PI), Rad2degJitHelper::rad2degArgv(\M_PI));
    }

    public function testKernelFilesRemoved(): void
    {
        $root = __DIR__.'/../..';
        $this->assertFileDoesNotExist($root.'/ext/standard/JitDeg2radKernel.php');
        $this->assertFileDoesNotExist($root.'/ext/standard/JitRad2degKernel.php');
        $this->assertFileDoesNotExist($root.'/ext/standard/phpc_deg2rad_kernel.php');
        $this->assertFileDoesNotExist($root.'/ext/standard/phpc_rad2deg_kernel.php');
    }

    public function testContextNoLongerAllowlistsDeg2radKernels(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringNotContainsString('phpc_deg2rad_kernel', $source);
        $this->assertStringNotContainsString('phpc_rad2deg_kernel', $source);
    }

    public function testSpineBundleIncludesDeg2radHelpersWithoutKernels(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('Deg2radJitHelper.php', $spine);
        $this->assertStringContainsString('Rad2degJitHelper.php', $spine);
        $this->assertStringContainsString('MathDeg2rad.php', $spine);
        $this->assertStringContainsString('MathRad2deg.php', $spine);
        $this->assertStringNotContainsString('JitDeg2radKernel.php', $spine);
        $this->assertStringNotContainsString('JitRad2degKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_deg2rad_kernel.php', $spine);
        $this->assertStringNotContainsString('phpc_rad2deg_kernel.php', $spine);
    }
}
