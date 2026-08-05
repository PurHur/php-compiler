<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\CeilJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * ceil() NestedJIT via JitVmHelperLink::ensureBridge (#27650 / peer deg2rad #27400).
 */
final class CeilRuntimeShrinkTest extends TestCase
{
    public function testCeilUsesJitHelperNotKernel(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/ceil.php');
        $this->assertStringContainsString('MathCeil::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('ceil')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathCeil.php');
        $this->assertStringContainsString('CeilJitHelper', $bridge);
        $this->assertStringContainsString('phpc_ceil', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('JitCeilKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testCeilJitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/CeilJitHelper.php');
        $this->assertStringContainsString('(int) $num', $source);
        $this->assertStringNotContainsString('9007199254740992.0', $source);
        $this->assertStringNotContainsString('self::INTEGRAL', $source);
        $this->assertStringNotContainsString('phpc_ceil_kernel', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function ceilArgv\(.*?\{[^}]*VmMath::ceil/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function ceilArgv\(.*?\{[^}]*\\\\ceil\(/s',
            $source
        );

        $this->assertSame(VmMath::ceil(1.2), CeilJitHelper::ceilArgv(1.2));
        $this->assertSame(VmMath::ceil(-1.7), CeilJitHelper::ceilArgv(-1.7));
        $this->assertSame(
            \unpack('P', \pack('d', VmMath::ceil(-0.1)))[1],
            \unpack('P', \pack('d', CeilJitHelper::ceilArgv(-0.1)))[1]
        );
    }

    public function testKernelFilesRemoved(): void
    {
        $root = __DIR__.'/../..';
        $this->assertFileDoesNotExist($root.'/ext/standard/JitCeilKernel.php');
        $this->assertFileDoesNotExist($root.'/ext/standard/phpc_ceil_kernel.php');
    }

    public function testContextNoLongerAllowlistsCeilKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringNotContainsString('phpc_ceil_kernel', $source);
        $this->assertStringContainsString('phpc_hypot_kernel', $source);
    }

    public function testSpineBundleIncludesCeilHelperWithoutKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('CeilJitHelper.php', $spine);
        $this->assertStringContainsString('MathCeil.php', $spine);
        $this->assertStringNotContainsString('JitCeilKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_ceil_kernel.php', $spine);
    }
}
