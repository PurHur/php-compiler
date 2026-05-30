<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** str_repeat() ValueError parity (issue #3735). */
final class StrRepeatBuiltinTest extends TestCase
{
    public function testExecuteNegativeTimesThrowsValueError(): void
    {
        $runtime = new Runtime();
        $fn = new ext\standard\str_repeat();
        $frame = $fn->getFrame($runtime->vmContext);
        $str = new VM\Variable();
        $str->string('x');
        $mult = new VM\Variable();
        $mult->int(-1);
        $frame->calledArgs = [$str, $mult];
        $frame->returnVar = new VM\Variable();
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('str_repeat(): Argument #2 ($times) must be greater than or equal to 0');
        $fn->execute($frame);
    }

    public function testExecuteNegativeTimesThrowsWhenReturnDiscarded(): void
    {
        $runtime = new Runtime();
        $fn = new ext\standard\str_repeat();
        $frame = $fn->getFrame($runtime->vmContext);
        $str = new VM\Variable();
        $str->string('x');
        $mult = new VM\Variable();
        $mult->int(-1);
        $frame->calledArgs = [$str, $mult];
        $frame->returnVar = null;
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('str_repeat(): Argument #2 ($times) must be greater than or equal to 0');
        $fn->execute($frame);
    }
}
