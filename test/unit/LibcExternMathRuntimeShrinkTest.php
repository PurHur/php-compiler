<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * LibcExtern no longer registers libc math after Math NestedJIT migrations (#28808).
 *
 * Last live caller was stats_standard_deviation → MathSqrt::invoke (#27888).
 */
final class LibcExternMathRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function deletedMathDecls(): array
    {
        return [
            'pow',
            'hypot',
            'cos',
            'cosh',
            'sin',
            'sinh',
            'tan',
            'tanh',
            'acos',
            'asin',
            'atan',
            'atan2',
            'acosh',
            'asinh',
            'atanh',
            'exp',
            'expm1',
            'log',
            'log10',
            'log1p',
            'sqrt',
        ];
    }

    public function testLibcExternDropsMathDecls(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        foreach ($this->deletedMathDecls() as $sym) {
            $this->assertStringNotContainsString(
                "'{$sym}' =>",
                $source,
                "LibcExtern must not declare libc {$sym} (#28808)"
            );
        }
        $this->assertStringContainsString('#28808', $source);
        // strtod dropped from always-on (#31997) — module-local ensureStrtodDecl
        $this->assertStringNotContainsString("'strtod' =>", $source);
        $this->assertStringContainsString('ensureStrtodDecl', $source);
        $this->assertStringContainsString('#31997', $source);
        // strlen dropped from always-on (#32068) — module-local ensureStrlenDecl
        $this->assertStringNotContainsString("'strlen' =>", $source);
        $this->assertStringContainsString('ensureStrlenDecl', $source);
        $this->assertStringContainsString('#32068', $source);
        // snprintf dropped from always-on (#32092) — module-local ensureSnprintf
        $this->assertStringNotContainsString("'snprintf' =>", $source);
        $this->assertStringContainsString('ensureSnprintf', $source);
        $this->assertStringContainsString('#32092', $source);
    }

    public function testJitStatsStandardDeviationUsesMathSqrtNotLibc(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/stats/JitStats.php');
        $this->assertStringContainsString('MathSqrt::invoke', $source);
        $this->assertStringContainsString('use PHPCompiler\\JIT\\Builtin\\MathSqrt;', $source);
        $this->assertStringNotContainsString("lookupFunction('sqrt')", $source);
        $this->assertStringNotContainsString('LibcExtern::', $source);
    }

    public function testSqrtBuiltinStillUsesMathSqrtBridge(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/sqrt.php');
        $this->assertStringContainsString('MathSqrt::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('sqrt')", $builtin);
    }
}
