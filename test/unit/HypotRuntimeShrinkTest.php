<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\HypotJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** hypot() JIT routes through HypotJitHelper PHP not libc LLVM (#15074). */
final class HypotRuntimeShrinkTest extends TestCase
{
    public function testHypotUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/hypot.php');
        $this->assertStringContainsString('MathHypot::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('hypot')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathHypot.php');
        $this->assertStringContainsString('HypotJitHelper', $bridge);
        $this->assertStringContainsString('phpc_hypot', $bridge);
    }

    public function testHypotJitHelperDelegatesToVmMath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/HypotJitHelper.php');
        $this->assertStringContainsString('VmMath::hypot', $source);

        $this->assertSame(
            VmMath::hypot(3.0, 4.0),
            HypotJitHelper::hypotArgv(3.0, 4.0)
        );
        $this->assertSame(
            VmMath::hypot(0.0, 5.0),
            HypotJitHelper::hypotArgv(0.0, 5.0)
        );
    }

    public function testSpineBundleIncludesHypotJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('HypotJitHelper.php', $spine);
        $this->assertStringContainsString('MathHypot.php', $spine);
    }
}
