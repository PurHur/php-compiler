<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\AsinJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** asin() JIT routes through AsinJitHelper PHP not libc LLVM (#15130). */
final class AsinRuntimeShrinkTest extends TestCase
{
    public function testAsinUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/asin.php');
        $this->assertStringContainsString('MathAsin::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('asin')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathAsin.php');
        $this->assertStringContainsString('AsinJitHelper', $bridge);
        $this->assertStringContainsString('phpc_asin', $bridge);
    }

    public function testAsinJitHelperDelegatesToVmMath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/AsinJitHelper.php');
        $this->assertStringContainsString('VmMath::asin', $source);

        $this->assertSame(
            VmMath::asin(0.0),
            AsinJitHelper::asinArgv(0.0)
        );
        $this->assertSame(
            VmMath::asin(0.5),
            AsinJitHelper::asinArgv(0.5)
        );
    }

    public function testSpineBundleIncludesAsinJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('AsinJitHelper.php', $spine);
        $this->assertStringContainsString('MathAsin.php', $spine);
    }
}
