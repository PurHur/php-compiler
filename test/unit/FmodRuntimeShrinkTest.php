<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FmodJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * fmod() AOT uses libm fmod(3) (#36386);
 * FmodJitHelper remains NestedJIT-safe reference (peer MathHypot / HypotJitHelper).
 * LLVM 9 has no llvm.fmod.f64.
 *
 * php-src: ext/standard/math.c PHP_FUNCTION(fmod).
 */
final class FmodRuntimeShrinkTest extends TestCase
{
    public function testFmodUsesLibmNotHelperBridge(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/fmod.php');
        $this->assertStringContainsString('MathFmod::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('fmod')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathFmod.php');
        $this->assertStringContainsString("LIBC_FMOD = 'fmod'", $bridge);
        $this->assertStringContainsString('phpc_fmod', $bridge);
        $this->assertStringContainsString('fmod_libm_f64_entry', $bridge);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('FmodJitHelper', $bridge);
        $this->assertStringNotContainsString('JitFmodKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
        $this->assertStringNotContainsString('llvm.fmod', $bridge);
    }

    public function testFmodJitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/FmodJitHelper.php');
        $this->assertStringContainsString('(int) $q', $source);
        $this->assertStringNotContainsString('phpc_fmod_kernel', $source);
        $this->assertStringNotContainsString('while (', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function fmodArgv\(.*?\{[^}]*VmMath::fmod/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function fmodArgv\(.*?\{[^}]*\\\\fmod\(/s',
            $source
        );

        $this->assertSame(VmMath::fmod(5.5, 2.0), FmodJitHelper::fmodArgv(5.5, 2.0));
        $this->assertSame(VmMath::fmod(-1.5, 1.2), FmodJitHelper::fmodArgv(-1.5, 1.2));
        $this->assertSame(VmMath::fmod(5.7, 1.3), FmodJitHelper::fmodArgv(5.7, 1.3));
        $this->assertSame(VmMath::fmod(-7.0, 3.0), FmodJitHelper::fmodArgv(-7.0, 3.0));
        $this->assertSame(
            \unpack('P', \pack('d', VmMath::fmod(-0.0, 1.0)))[1],
            \unpack('P', \pack('d', FmodJitHelper::fmodArgv(-0.0, 1.0)))[1]
        );
        $this->assertTrue(\is_nan(FmodJitHelper::fmodArgv(1.0, 0.0)));
        $this->assertTrue(\is_nan(FmodJitHelper::fmodArgv(\INF, 1.0)));
    }

    public function testKernelFilesRemoved(): void
    {
        $root = __DIR__.'/../..';
        $this->assertFileDoesNotExist($root.'/ext/standard/JitFmodKernel.php');
        $this->assertFileDoesNotExist($root.'/ext/standard/phpc_fmod_kernel.php');
    }

    public function testContextNoLongerAllowlistsFmodKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringNotContainsString('phpc_fmod_kernel', $source);
    }

    public function testSpineBundleIncludesFmodHelperWithoutKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FmodJitHelper.php', $spine);
        $this->assertStringContainsString('MathFmod.php', $spine);
        $this->assertStringNotContainsString('JitFmodKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_fmod_kernel.php', $spine);
    }
}
