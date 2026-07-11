<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\NextafterJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** nextafter() JIT routes through NextafterJitHelper PHP not libc LLVM (#15062). */
final class NextafterRuntimeShrinkTest extends TestCase
{
    public function testNextafterUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/nextafter.php');
        $this->assertStringContainsString('MathNextafter::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('nextafter')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathNextafter.php');
        $this->assertStringContainsString('NextafterJitHelper', $bridge);
        $this->assertStringContainsString('phpc_nextafter', $bridge);
    }

    public function testNextafterJitHelperDelegatesToLibc(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/NextafterJitHelper.php');
        $this->assertStringContainsString('\\nextafter(', $source);
        $this->assertStringNotContainsString('return VmMath::nextafter', $source);

        $this->assertSame(
            \nextafter(1.0, 2.0),
            NextafterJitHelper::nextafterArgv(1.0, 2.0)
        );
        $this->assertSame(
            \nextafter(1.0, 0.0),
            NextafterJitHelper::nextafterArgv(1.0, 0.0)
        );
    }

    public function testSpineBundleIncludesNextafterJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('NextafterJitHelper.php', $spine);
        $this->assertStringContainsString('MathNextafter.php', $spine);
    }
}
