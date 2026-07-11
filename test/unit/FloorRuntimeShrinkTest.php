<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FloorJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** floor() JIT routes through FloorJitHelper PHP not libc LLVM (#15128). */
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
    }

    public function testFloorJitHelperDelegatesToVmMath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/FloorJitHelper.php');
        $this->assertStringContainsString('VmMath::floor', $source);

        $this->assertSame(
            VmMath::floor(1.7),
            FloorJitHelper::floorArgv(1.7)
        );
        $this->assertSame(
            VmMath::floor(-1.2),
            FloorJitHelper::floorArgv(-1.2)
        );
    }

    public function testSpineBundleIncludesFloorJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FloorJitHelper.php', $spine);
        $this->assertStringContainsString('MathFloor.php', $spine);
    }
}
