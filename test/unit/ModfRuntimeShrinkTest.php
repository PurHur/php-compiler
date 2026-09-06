<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ModfJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * Internal modf AOT uses libm modf(3) (#36386);
 * ModfJitHelper remains NestedJIT-safe reference (peer MathFrexp / FrexpJitHelper).
 * Userland modf() was a phantom vs php-src and was unregistered (#25359).
 * LLVM 9 has no llvm.modf.f64 in the shapes we use.
 *
 * php-src: ext/standard/math.c PHP_FUNCTION(modf) → C modf(3).
 */
final class ModfRuntimeShrinkTest extends TestCase
{
    public function testModfUsesLibmNotHelperBridge(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathModf.php');
        $this->assertStringContainsString("LIBC_MODF = 'modf'", $bridge);
        $this->assertStringContainsString('phpc_modf', $bridge);
        $this->assertStringContainsString('modf_libm_f64_entry', $bridge);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiled', $bridge);
        $this->assertStringNotContainsString('ModfJitHelper', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
        $this->assertStringNotContainsString('llvm.modf', $bridge);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/modf.php');
    }

    public function testModfJitHelperInlinesNestedJitSafeTrunc(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ModfJitHelper.php');
        $this->assertStringContainsString('truncTowardInfinity', $source);
        $this->assertStringNotContainsString('\\floor(', $source);
        $this->assertStringNotContainsString('\\ceil(', $source);
        $this->assertStringNotContainsString('\\is_nan(', $source);
        $this->assertStringNotContainsString('\\is_infinite(', $source);
        // Doc may name Floor/Ceil peers; compute must not call them (#27838 class).
        $this->assertStringNotContainsString('FloorJitHelper::', $source);
        $this->assertStringNotContainsString('CeilJitHelper::', $source);
        // Docblock may {@see} VmMath; compute body must not call it (#29244).
        $this->assertDoesNotMatchRegularExpression(
            '/function compute\(.*?\{[^}]*VmMath::modf/s',
            $source
        );

        ModfJitHelper::resetForTest();
        $this->assertSame(0.75, ModfJitHelper::compute(3.75));
        $this->assertSame(3.0, ModfJitHelper::intPart());

        ModfJitHelper::resetForTest();
        $this->assertSame(-0.75, ModfJitHelper::compute(-3.75));
        $this->assertSame(-3.0, ModfJitHelper::intPart());

        ModfJitHelper::resetForTest();
        $this->assertSame(0.0, ModfJitHelper::compute(0.0));
        $this->assertSame(0.0, ModfJitHelper::intPart());

        ModfJitHelper::resetForTest();
        $this->assertSame(0.0, ModfJitHelper::compute(5.0));
        $this->assertSame(5.0, ModfJitHelper::intPart());

        // Normals agree with VmMath (VmMath uses floor/ceil), including (-1,0) → -0 int.
        foreach ([0.1, 0.5, 1.25, 2.0, 8.75, -0.25, -0.5, -1.5, -8.0, 1e10, -1e10] as $n) {
            $intPart = 0.0;
            $expected = VmMath::modf($n, $intPart);
            ModfJitHelper::resetForTest();
            $this->assertSame($expected, ModfJitHelper::compute($n), 'frac for '.$n);
            $this->assertSame($intPart, ModfJitHelper::intPart(), 'int for '.$n);
        }

        ModfJitHelper::resetForTest();
        $this->assertTrue(\is_nan(ModfJitHelper::compute(\NAN)));
        $this->assertTrue(\is_nan(ModfJitHelper::intPart()));

        ModfJitHelper::resetForTest();
        $this->assertTrue(\is_infinite(ModfJitHelper::compute(\INF)));
        $this->assertTrue(\is_infinite(ModfJitHelper::intPart()));

        ModfJitHelper::resetForTest();
        $this->assertTrue(\is_infinite(ModfJitHelper::compute(-\INF)));
        $this->assertTrue(\is_infinite(ModfJitHelper::intPart()));
        $this->assertLessThan(0.0, ModfJitHelper::intPart());
    }

    public function testLibcExternNoLongerDeclaresModf(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringNotContainsString("'modf'", $source);
    }

    public function testSpineBundleIncludesModfHelperWithoutUserland(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ModfJitHelper.php', $spine);
        $this->assertStringContainsString('MathModf.php', $spine);
        $this->assertStringNotContainsString('ext/standard/modf.php', $spine);
    }
}
