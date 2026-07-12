<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\clamp;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin clamp() (ext/standard/math.c). */
final class ClampBuiltinTest extends TestCase
{
    public function testClampAboveMax(): void
    {
        $this->assertSame(3, $this->runClamp(5, 1, 3));
    }

    public function testClampBelowMin(): void
    {
        $this->assertSame(1, $this->runClamp(0, 1, 3));
    }

    public function testClampInRange(): void
    {
        $this->assertSame(2, $this->runClamp(2, 1, 3));
    }

    public function testClampMinGreaterThanMaxThrows(): void
    {
        $this->expectException(\ValueError::class);
        $this->runClamp(1, 3, 2);
    }

    public function testClampNanMinThrows(): void
    {
        $this->expectException(\ValueError::class);
        $this->runClamp(1.0, NAN, 2.0);
    }

    private function runClamp(int|float $value, int|float $min, int|float $max): int|float
    {
        $runtime = new Runtime();
        $fn = new clamp();
        $frame = $fn->getFrame($runtime->vmContext);
        foreach ([$value, $min, $max] as $i => $scalar) {
            $arg = new VMVariable();
            if (\is_int($scalar)) {
                $arg->int($scalar);
            } else {
                $arg->float($scalar);
            }
            $frame->calledArgs[$i] = $arg;
        }
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $ret = $frame->returnVar->resolveIndirect();

        return Variable::TYPE_INTEGER === $ret->type ? $ret->toInt() : $ret->toFloat();
    }
}
