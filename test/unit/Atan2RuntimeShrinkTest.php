<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\Atan2JitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** atan2() JIT: always Atan2JitHelper via JitVmHelperLink + phpc_atan2_kernel (#15102, #27017). */
final class Atan2RuntimeShrinkTest extends TestCase
{
    public function testAtan2UsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/atan2.php');
        $this->assertStringContainsString('MathAtan2::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('atan2')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathAtan2.php');
        $this->assertStringContainsString('Atan2JitHelper', $bridge);
        $this->assertStringContainsString('phpc_atan2', $bridge);
        $this->assertStringContainsString('JitAtan2Kernel', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $bridge);
    }

    public function testAtan2JitHelperDelegatesToKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Atan2JitHelper.php');
        $this->assertStringContainsString('phpc_atan2_kernel', $source);
        $this->assertMatchesRegularExpression(
            '/function atan2Argv\(.*?\{[^}]*phpc_atan2_kernel/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function atan2Argv\(.*?\{[^}]*VmMath::atan2/s',
            $source
        );

        if (!\function_exists('phpc_atan2_kernel')) {
            $this->markTestSkipped('phpc_atan2_kernel requires compiler runtime');
        }
        $this->assertSame(
            VmMath::atan2(1.0, 1.0),
            Atan2JitHelper::atan2Argv(1.0, 1.0)
        );
        $this->assertSame(
            VmMath::atan2(0.0, 1.0),
            Atan2JitHelper::atan2Argv(0.0, 1.0)
        );
    }

    public function testSpineBundleIncludesAtan2JitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('Atan2JitHelper.php', $spine);
        $this->assertStringContainsString('MathAtan2.php', $spine);
        $this->assertStringContainsString('JitAtan2Kernel.php', $spine);
        $this->assertStringContainsString('phpc_atan2_kernel.php', $spine);
    }
}
