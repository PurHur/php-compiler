<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\Expm1JitHelper;
use PHPCompiler\ext\standard\Log1pJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** log1p()/expm1() JIT routes through JitHelper PHP not libc LLVM (#15157). */
final class Log1pExpm1RuntimeShrinkTest extends TestCase
{
    public function testLog1pUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/log1p.php');
        $this->assertStringContainsString('MathLog1p::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('log1p')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathLog1p.php');
        $this->assertStringContainsString('Log1pJitHelper', $bridge);
        $this->assertStringContainsString('phpc_log1p', $bridge);
    }

    public function testExpm1UsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/expm1.php');
        $this->assertStringContainsString('MathExpm1::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('expm1')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathExpm1.php');
        $this->assertStringContainsString('Expm1JitHelper', $bridge);
        $this->assertStringContainsString('phpc_expm1', $bridge);
    }

    public function testJitHelpersDelegateToVmMath(): void
    {
        $this->assertSame(VmMath::log1p(0.0), Log1pJitHelper::log1pArgv(0.0));
        $this->assertSame(VmMath::expm1(1.0), Expm1JitHelper::expm1Argv(1.0));
    }

    public function testSpineBundleIncludesLog1pExpm1JitHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('Log1pJitHelper.php', $spine);
        $this->assertStringContainsString('Expm1JitHelper.php', $spine);
        $this->assertStringContainsString('MathLog1p.php', $spine);
        $this->assertStringContainsString('MathExpm1.php', $spine);
    }
}
