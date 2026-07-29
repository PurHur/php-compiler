<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\LdexpJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * Internal ldexp math stays PHP-in-PHP via LdexpJitHelper (#15073).
 * Userland ldexp() was a phantom vs php-src and was unregistered (#24607).
 */
final class LdexpRuntimeShrinkTest extends TestCase
{
    public function testMathLdexpBridgeUsesJitHelperNotLibcLookup(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathLdexp.php');
        $this->assertStringContainsString('LdexpJitHelper', $bridge);
        $this->assertStringContainsString('phpc_ldexp', $bridge);
        $this->assertStringNotContainsString("lookupFunction('ldexp')", $bridge);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/ldexp.php');
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
        $this->assertStringNotContainsString('ext/standard/ldexp.php', $spine);
    }
}
