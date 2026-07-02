<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\CosJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** cos() JIT routes through CosJitHelper PHP not libc LLVM (#15087). */
final class CosRuntimeShrinkTest extends TestCase
{
    public function testCosUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/cos.php');
        $this->assertStringContainsString('MathCos::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('cos')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathCos.php');
        $this->assertStringContainsString('CosJitHelper', $bridge);
        $this->assertStringContainsString('phpc_cos', $bridge);
    }

    public function testCosJitHelperDelegatesToVmMath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/CosJitHelper.php');
        $this->assertStringContainsString('VmMath::cos', $source);

        $this->assertSame(
            VmMath::cos(0.0),
            CosJitHelper::cosArgv(0.0)
        );
        $this->assertSame(
            VmMath::cos(\deg2rad(0.0)),
            CosJitHelper::cosArgv(\deg2rad(0.0))
        );
    }

    public function testSpineBundleIncludesCosJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('CosJitHelper.php', $spine);
        $this->assertStringContainsString('MathCos.php', $spine);
    }
}
