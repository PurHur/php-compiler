<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\IsFiniteJitHelper;
use PHPUnit\Framework\TestCase;

/** is_finite() JIT routes through IsFiniteJitHelper PHP not isnan/isinf LLVM (#15188). */
final class IsFiniteRuntimeShrinkTest extends TestCase
{
    public function testIsFiniteUsesJitHelperNotLibcCompose(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/is_finite.php');
        $this->assertStringContainsString('MathIsFinite::invoke', $builtin);
        $this->assertStringNotContainsString('JitIsFinite', $builtin);

        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/JitIsFinite.php');

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathIsFinite.php');
        $this->assertStringContainsString('IsFiniteJitHelper', $bridge);
        $this->assertStringContainsString('phpc_is_finite', $bridge);
    }

    public function testIsFiniteJitHelperDelegatesToPhpIsFinite(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/IsFiniteJitHelper.php');
        $this->assertStringContainsString('\\is_finite', $source);

        $this->assertSame(\is_finite(1.0), IsFiniteJitHelper::isFiniteArgv(1.0));
        $this->assertSame(\is_finite(\INF), IsFiniteJitHelper::isFiniteArgv(\INF));
    }

    public function testSpineBundleIncludesIsFiniteJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('IsFiniteJitHelper.php', $spine);
        $this->assertStringContainsString('MathIsFinite.php', $spine);
        $this->assertStringNotContainsString('JitIsFinite.php', $spine);
    }
}
