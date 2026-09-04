<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FloorJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * floor() AOT uses llvm.floor.f64 (#36386); FloorJitHelper remains NestedJIT-safe
 * reference (peer MathSqrt / SqrtJitHelper).
 *
 * php-src: ext/standard/math.c PHP_FUNCTION(floor).
 */
final class FloorRuntimeShrinkTest extends TestCase
{
    public function testFloorUsesLlvmIntrinsicNotHelperBridge(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/floor.php');
        $this->assertStringContainsString('MathFloor::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('floor')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathFloor.php');
        $this->assertStringContainsString('llvm.floor.f64', $bridge);
        $this->assertStringContainsString('phpc_floor', $bridge);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('FloorJitHelper', $bridge);
        $this->assertStringNotContainsString('JitFloorKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testFloorJitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/FloorJitHelper.php');
        $this->assertStringContainsString('(int) $num', $source);
        $this->assertStringNotContainsString('9007199254740992.0', $source);
        $this->assertStringNotContainsString('self::INTEGRAL', $source);
        $this->assertStringNotContainsString('phpc_floor_kernel', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function floorArgv\(.*?\{[^}]*VmMath::floor/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function floorArgv\(.*?\{[^}]*\\\\floor\(/s',
            $source
        );

        $this->assertSame(VmMath::floor(1.7), FloorJitHelper::floorArgv(1.7));
        $this->assertSame(VmMath::floor(-1.2), FloorJitHelper::floorArgv(-1.2));
        $this->assertSame(
            \unpack('P', \pack('d', VmMath::floor(-0.0)))[1],
            \unpack('P', \pack('d', FloorJitHelper::floorArgv(-0.0)))[1]
        );
    }

    public function testKernelFilesRemoved(): void
    {
        $root = __DIR__.'/../..';
        $this->assertFileDoesNotExist($root.'/ext/standard/JitFloorKernel.php');
        $this->assertFileDoesNotExist($root.'/ext/standard/phpc_floor_kernel.php');
    }

    public function testContextNoLongerAllowlistsFloorKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringNotContainsString('phpc_floor_kernel', $source);
        $this->assertStringNotContainsString('phpc_ceil_kernel', $source);
    }

    public function testSpineBundleIncludesFloorHelperWithoutKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FloorJitHelper.php', $spine);
        $this->assertStringContainsString('MathFloor.php', $spine);
        $this->assertStringNotContainsString('JitFloorKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_floor_kernel.php', $spine);
    }
}
