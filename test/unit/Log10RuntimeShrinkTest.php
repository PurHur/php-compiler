<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\Log10JitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** log10() JIT routes through Log10JitHelper PHP not libc LLVM (#15101). */
final class Log10RuntimeShrinkTest extends TestCase
{
    public function testLog10UsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/log10.php');
        $this->assertStringContainsString('MathLog10::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('log10')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathLog10.php');
        $this->assertStringContainsString('Log10JitHelper', $bridge);
        $this->assertStringContainsString('phpc_log10', $bridge);
    }

    public function testLog10JitHelperDelegatesToVmMath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Log10JitHelper.php');
        $this->assertStringContainsString('VmMath::log10', $source);

        $this->assertSame(
            VmMath::log10(100.0),
            Log10JitHelper::log10Argv(100.0)
        );
        $this->assertSame(
            VmMath::log10(1.0),
            Log10JitHelper::log10Argv(1.0)
        );
    }

    public function testSpineBundleIncludesLog10JitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('Log10JitHelper.php', $spine);
        $this->assertStringContainsString('MathLog10.php', $spine);
    }
}
