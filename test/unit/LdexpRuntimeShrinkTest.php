<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\LdexpJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * Internal ldexp AOT uses libm ldexp(3) (#36386);
 * LdexpJitHelper remains NestedJIT-safe reference (peer MathNextafter / NextafterJitHelper).
 * Userland ldexp() was a phantom vs php-src and was unregistered (#24607).
 * LLVM 9 has no llvm.ldexp.f64 in the shapes we use.
 *
 * php-src: ext/standard/math.c PHP_FUNCTION(ldexp) → C ldexp(3).
 */
final class LdexpRuntimeShrinkTest extends TestCase
{
    public function testLdexpUsesLibmNotHelperBridge(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathLdexp.php');
        $this->assertStringContainsString("LIBC_LDEXP = 'ldexp'", $bridge);
        $this->assertStringContainsString('phpc_ldexp', $bridge);
        $this->assertStringContainsString('ldexp_libm_f64_entry', $bridge);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('LdexpJitHelper', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
        $this->assertStringNotContainsString('llvm.ldexp', $bridge);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/ldexp.php');
    }

    public function testLdexpJitHelperInlinesNestedJitSafePeel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/LdexpJitHelper.php');
        $this->assertStringContainsString('2048', $source);
        $this->assertStringContainsString('2.0', $source);
        $this->assertStringContainsString('0.5', $source);
        $this->assertStringNotContainsString('\\is_nan(', $source);
        $this->assertStringNotContainsString('\\is_infinite(', $source);
        $this->assertStringNotContainsString('2 **', $source);
        $this->assertStringNotContainsString('** $exp', $source);
        $this->assertStringNotContainsString('VmMath::ldexp', $source);

        $this->assertSame(12.0, LdexpJitHelper::ldexpArgv(3.0, 2));
        $this->assertSame(0.75, LdexpJitHelper::ldexpArgv(1.5, -1));
        $this->assertSame(0.0, LdexpJitHelper::ldexpArgv(0.0, 5));
        $this->assertSame(3.0, LdexpJitHelper::ldexpArgv(3.0, 0));
        $this->assertSame(-12.0, LdexpJitHelper::ldexpArgv(-3.0, 2));

        // Normals agree with VmMath (VmMath uses is_nan/is_infinite/2**).
        foreach (
            [
                [0.1, 3],
                [0.5, -2],
                [2.0, 10],
                [8.0, -3],
                [1.5, 1],
                [3.0, -4],
                [-0.5, 5],
                [1e-300, 20],
                [1e300, -10],
                [\PHP_FLOAT_MIN, 1],
            ] as [$n, $e]
        ) {
            $this->assertSame(
                VmMath::ldexp($n, $e),
                LdexpJitHelper::ldexpArgv($n, $e),
                'ldexp('.$n.', '.$e.')'
            );
        }

        $this->assertTrue(\is_nan(LdexpJitHelper::ldexpArgv(\NAN, 3)));
        $this->assertTrue(\is_infinite(LdexpJitHelper::ldexpArgv(\INF, 2)));
        $this->assertTrue(\is_infinite(LdexpJitHelper::ldexpArgv(-\INF, -1)));
        $this->assertTrue(\is_infinite(LdexpJitHelper::ldexpArgv(1.0, 2000)));
        $this->assertSame(0.0, LdexpJitHelper::ldexpArgv(1.0, -2000));
    }

    public function testLibcExternNoLongerDeclaresLdexp(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringNotContainsString("'ldexp'", $source);
    }

    public function testSpineBundleIncludesLdexpHelperWithoutUserland(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('LdexpJitHelper.php', $spine);
        $this->assertStringContainsString('MathLdexp.php', $spine);
        $this->assertStringNotContainsString('ext/standard/ldexp.php', $spine);
    }
}
