<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FpowJitHelper;
use PHPUnit\Framework\TestCase;

/** fpow()/pow float JIT routes through FpowJitHelper PHP not libc pow (#15189). */
final class FpowRuntimeShrinkTest extends TestCase
{
    public function testFpowUsesJitHelperNotLibcPow(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/fpow.php');
        $this->assertStringContainsString('MathFpow::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('pow')", $builtin);

        $jitPow = (string) file_get_contents(__DIR__.'/../../ext/standard/JitPow.php');
        $this->assertStringContainsString('MathFpow::invoke', $jitPow);
        $this->assertStringNotContainsString("lookupFunction('pow')", $jitPow);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathFpow.php');
        $this->assertStringContainsString('FpowJitHelper', $bridge);
        $this->assertStringContainsString('phpc_fpow', $bridge);
    }

    public function testFpowJitHelperDelegatesToPow(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/FpowJitHelper.php');
        $this->assertStringContainsString('\\pow(', $source);
        $this->assertStringNotContainsString('return VmMath::fpow', $source);

        $this->assertSame(8.0, FpowJitHelper::fpowArgv(2.0, 3.0));
        $this->assertSame(\pow(2.5, 1.5), FpowJitHelper::fpowArgv(2.5, 1.5));
    }

    public function testSpineBundleIncludesFpowJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FpowJitHelper.php', $spine);
        $this->assertStringContainsString('MathFpow.php', $spine);
    }
}
