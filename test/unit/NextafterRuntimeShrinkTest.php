<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\NextafterJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * nextafter() AOT uses libm nextafter(3) (#36386);
 * NextafterJitHelper remains NestedJIT-safe reference (peer MathAtan2 / Atan2JitHelper).
 * LLVM 9 has no llvm.nextafter.f64.
 *
 * php-src: ext/standard/math.c PHP_FUNCTION(nextafter).
 */
final class NextafterRuntimeShrinkTest extends TestCase
{
    public function testNextafterUsesLibmNotHelperBridge(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/nextafter.php');
        $this->assertStringContainsString('MathNextafter::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('nextafter')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathNextafter.php');
        $this->assertStringContainsString("LIBC_NEXTAFTER = 'nextafter'", $bridge);
        $this->assertStringContainsString('phpc_nextafter', $bridge);
        $this->assertStringContainsString('nextafter_libm_f64_entry', $bridge);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('NextafterJitHelper', $bridge);
        $this->assertStringNotContainsString('JitNextafterKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
        $this->assertStringNotContainsString('llvm.nextafter', $bridge);
    }

    public function testNextafterJitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/NextafterJitHelper.php');
        $this->assertStringContainsString('minPos', $source);
        $this->assertStringContainsString('2048', $source);
        $this->assertStringContainsString('0.5 === $m', $source);
        $this->assertStringNotContainsString('phpc_nextafter_kernel', $source);
        $this->assertStringNotContainsString('pack(', $source);
        $this->assertStringNotContainsString('unpack(', $source);
        $this->assertStringNotContainsString('\\nextafter(', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function nextafterArgv\(.*?\{[^}]*VmMath::nextafter/s',
            $source
        );

        // Match IEEE nextafter / former bitcast kernel (not VmMath pack path for ±0→−min).
        $this->assertSame(1.0000000000000002, NextafterJitHelper::nextafterArgv(1.0, 2.0));
        $this->assertSame(0.9999999999999999, NextafterJitHelper::nextafterArgv(1.0, 0.0));
        $this->assertSame(5.0e-324, NextafterJitHelper::nextafterArgv(0.0, 1.0));
        $this->assertSame(-5.0e-324, NextafterJitHelper::nextafterArgv(0.0, -1.0));
        $this->assertSame(-0.9999999999999999, NextafterJitHelper::nextafterArgv(-1.0, 0.0));
        $this->assertSame(-1.0000000000000002, NextafterJitHelper::nextafterArgv(-1.0, -2.0));
        $this->assertSame(7.999999999999999, NextafterJitHelper::nextafterArgv(8.0, 0.0));
        $this->assertSame(1.9999999999999998, NextafterJitHelper::nextafterArgv(2.0, 1.0));
        $this->assertTrue(\is_infinite(NextafterJitHelper::nextafterArgv(\PHP_FLOAT_MAX, \INF)));
        $this->assertSame(\PHP_FLOAT_MAX, NextafterJitHelper::nextafterArgv(\INF, 0.0));
        $this->assertSame(-\PHP_FLOAT_MAX, NextafterJitHelper::nextafterArgv(-\INF, 0.0));
        $this->assertTrue(\is_nan(NextafterJitHelper::nextafterArgv(\NAN, 1.0)));
        $this->assertTrue(\is_nan(NextafterJitHelper::nextafterArgv(1.0, \NAN)));

        // Non-power-of-two normals agree with VmMath bit walk.
        $this->assertSame(VmMath::nextafter(1.5, 2.0), NextafterJitHelper::nextafterArgv(1.5, 2.0));
        $this->assertSame(VmMath::nextafter(3.0, 0.0), NextafterJitHelper::nextafterArgv(3.0, 0.0));
    }

    public function testLibcExternNoLongerDeclaresNextafter(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringNotContainsString("'nextafter'", $source);
    }

    public function testNextafterKernelFilesRemoved(): void
    {
        $root = __DIR__.'/../..';
        $this->assertFileDoesNotExist($root.'/ext/standard/JitNextafterKernel.php');
        $this->assertFileDoesNotExist($root.'/ext/standard/phpc_nextafter_kernel.php');
    }

    public function testContextNoLongerAllowlistsNextafterKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringNotContainsString('phpc_nextafter_kernel', $source);
    }

    public function testSpineBundleIncludesNextafterHelperWithoutKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('NextafterJitHelper.php', $spine);
        $this->assertStringContainsString('MathNextafter.php', $spine);
        $this->assertStringNotContainsString('JitNextafterKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_nextafter_kernel.php', $spine);
    }
}
