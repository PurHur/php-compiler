<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\LdexpJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** ldexp() JIT routes through LdexpJitHelper PHP not libc LLVM (#15073). */
final class LdexpRuntimeShrinkTest extends TestCase
{
    public function testLdexpUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/ldexp.php');
        $this->assertStringContainsString('MathLdexp::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('ldexp')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathLdexp.php');
        $this->assertStringContainsString('LdexpJitHelper', $bridge);
        $this->assertStringContainsString('phpc_ldexp', $bridge);
    }

    public function testLdexpJitHelperDelegatesToVmMath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/LdexpJitHelper.php');
        $this->assertStringContainsString('VmMath::ldexp', $source);

        $this->assertSame(
            VmMath::ldexp(3.0, 2),
            LdexpJitHelper::ldexpArgv(3.0, 2)
        );
        $this->assertSame(
            VmMath::ldexp(1.5, -1),
            LdexpJitHelper::ldexpArgv(1.5, -1)
        );
    }

    public function testSpineBundleIncludesLdexpJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('LdexpJitHelper.php', $spine);
        $this->assertStringContainsString('MathLdexp.php', $spine);
    }
}
