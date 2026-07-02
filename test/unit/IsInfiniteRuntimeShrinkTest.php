<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\IsInfiniteJitHelper;
use PHPUnit\Framework\TestCase;

/** is_infinite() JIT routes through IsInfiniteJitHelper PHP not libc isinf (#15174). */
final class IsInfiniteRuntimeShrinkTest extends TestCase
{
    public function testIsInfiniteUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/is_infinite.php');
        $this->assertStringContainsString('MathIsInfinite::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('isinf')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathIsInfinite.php');
        $this->assertStringContainsString('IsInfiniteJitHelper', $bridge);
        $this->assertStringContainsString('phpc_is_infinite', $bridge);
    }

    public function testIsInfiniteJitHelperDelegatesToPhpIsInfinite(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/IsInfiniteJitHelper.php');
        $this->assertStringContainsString('\\is_infinite', $source);

        $this->assertSame(\is_infinite(\INF), IsInfiniteJitHelper::isInfiniteArgv(\INF));
        $this->assertSame(\is_infinite(1.0), IsInfiniteJitHelper::isInfiniteArgv(1.0));
    }

    public function testSpineBundleIncludesIsInfiniteJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('IsInfiniteJitHelper.php', $spine);
        $this->assertStringContainsString('MathIsInfinite.php', $spine);
    }
}
