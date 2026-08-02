<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FloorJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** floor() JIT: always FloorJitHelper via JitVmHelperLink + phpc_floor_kernel (#15128, #27004). */
final class FloorRuntimeShrinkTest extends TestCase
{
    public function testFloorUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/floor.php');
        $this->assertStringContainsString('MathFloor::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('floor')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathFloor.php');
        $this->assertStringContainsString('FloorJitHelper', $bridge);
        $this->assertStringContainsString('phpc_floor', $bridge);
        $this->assertStringContainsString('JitFloorKernel', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $bridge);
    }

    public function testFloorJitHelperDelegatesToKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/FloorJitHelper.php');
        $this->assertStringContainsString('phpc_floor_kernel', $source);
        $this->assertMatchesRegularExpression(
            '/function floorArgv\(.*?\{[^}]*phpc_floor_kernel/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function floorArgv\(.*?\{[^}]*VmMath::floor/s',
            $source
        );

        if (!\function_exists('phpc_floor_kernel')) {
            $this->markTestSkipped('phpc_floor_kernel requires compiler runtime');
        }
        $this->assertSame(
            VmMath::floor(1.7),
            FloorJitHelper::floorArgv(1.7)
        );
        $this->assertSame(
            VmMath::floor(-1.2),
            FloorJitHelper::floorArgv(-1.2)
        );
    }

    public function testContextAllowlistsFloorKernelForNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('phpc_floor_kernel', $source);
        $this->assertStringContainsString('phpc_ceil_kernel', $source);
    }

    public function testSpineBundleIncludesFloorJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FloorJitHelper.php', $spine);
        $this->assertStringContainsString('MathFloor.php', $spine);
        $this->assertStringContainsString('JitFloorKernel.php', $spine);
        $this->assertStringContainsString('phpc_floor_kernel.php', $spine);
    }
}
