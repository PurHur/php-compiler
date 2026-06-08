<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;

/** Shared VM wiring for BcMath\Number methods (php-src ext/bcmath/bcmath.c; issue #7220). */
abstract class BcMathNumberMethod extends VmClassMethod
{
    protected function receiver(Frame $frame, string $label): \PHPCompiler\VM\ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($label.' called without $this');
        }

        return VmBcMathNumber::requireNumberReceiver($frame->calledArgs[0], $label);
    }

    protected function optionalScale(Frame $frame, int $index, string $method): ?int
    {
        if (\count($frame->calledArgs) <= $index) {
            return null;
        }

        return VmBcMathNumber::optionalScaleArg($frame->calledArgs[$index], $method, $index);
    }

    protected function returnNumber(Frame $frame, string $value, ?int $scale): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('BcMath\\Number method requires VM context in this compiler build');
        }
        $frame->returnVar->copyFrom(VmBcMathNumber::fromComputedValue($frame->vmContext, $value, $scale));
    }

    protected function coerceOperand(Frame $frame, int $index, string $method, string $paramName): string
    {
        return VmBcMathNumber::coerceOperand($frame->calledArgs[$index], $method, $index, $paramName);
    }
}
