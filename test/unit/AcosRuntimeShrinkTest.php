<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\AcosJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** acos() JIT routes through AcosJitHelper PHP not libc LLVM (#15141). */
final class AcosRuntimeShrinkTest extends TestCase
{
    public function testAcosUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/acos.php');
        $this->assertStringContainsString('MathAcos::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('acos')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathAcos.php');
        $this->assertStringContainsString('AcosJitHelper', $bridge);
        $this->assertStringContainsString('phpc_acos', $bridge);
    }

    public function testAcosJitHelperDelegatesToVmMath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/AcosJitHelper.php');
        $this->assertStringContainsString('VmMath::acos', $source);

        $this->assertSame(
            VmMath::acos(1.0),
            AcosJitHelper::acosArgv(1.0)
        );
        $this->assertSame(
            VmMath::acos(0.5),
            AcosJitHelper::acosArgv(0.5)
        );
    }

    public function testSpineBundleIncludesAcosJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('AcosJitHelper.php', $spine);
        $this->assertStringContainsString('MathAcos.php', $spine);
    }
}
