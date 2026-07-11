<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\PackEngine;
use PHPCompiler\ext\standard\PackJitEngine;
use PHPUnit\Framework\TestCase;

/** PackJitEngine native pack() matches PackEngine for JIT/AOT bridge (#13062). */
final class PackJitEngineTest extends TestCase
{
    public function testNativeFormatsMatchPackEngine(): void
    {
        foreach (
            [
                ['c', [65]],
                ['n', [0x1234]],
                ['a3', ['hi']],
                ['f', [1.5]],
                ['d', [-2.25]],
            ] as [$fmt, $args]
        ) {
            $this->assertSame(PackEngine::pack($fmt, $args), PackJitEngine::pack($fmt, $args), $fmt);
        }
    }
}
