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

    /** php-src ext/standard/pack.c — null value operands TypeError on 8.4 (#18992, #19388). */
    public function testNullValueOperandsTypeErrorOnForward84(): void
    {
        $prev = \getenv('PHP_COMPILER_PROFILE');
        \putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            foreach (['a*', 'H*', 'c'] as $fmt) {
                try {
                    PackEngine::pack($fmt, [null]);
                    $this->fail("PackEngine::$fmt expected TypeError");
                } catch (\TypeError $e) {
                    $this->assertStringContainsString('must be of type string, null given', $e->getMessage());
                }
                try {
                    PackJitEngine::pack($fmt, [null]);
                    $this->fail("PackJitEngine::$fmt expected TypeError");
                } catch (\TypeError $e) {
                    $this->assertStringContainsString('must be of type string, null given', $e->getMessage());
                }
            }
            $this->assertSame(\pack('c', 65), PackEngine::pack('c', [65]));
        } finally {
            if (false === $prev) {
                \putenv('PHP_COMPILER_PROFILE');
            } else {
                \putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
