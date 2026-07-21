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

        return VmBcMathNumber::optionalScaleArg($frame->calledArgs[$index], $method, $index, $frame);
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

    /** php-src BcMath\Number::add/sub — max operand scale when $scale is null. */
    protected function effectiveAddSubScale(\PHPCompiler\VM\ObjectEntry $receiver, string $right, ?int $scale): int
    {
        if (null !== $scale) {
            return $scale;
        }

        return max(VmBcMathNumber::objectScale($receiver), VmBcmath::decimalScale($right));
    }

    /** php-src BcMath\Number::mul — sum of operand scales when $scale is null. */
    protected function effectiveMulScale(\PHPCompiler\VM\ObjectEntry $receiver, string $right, ?int $scale): int
    {
        if (null !== $scale) {
            return $scale;
        }

        return VmBcMathNumber::objectScale($receiver) + VmBcmath::decimalScale($right);
    }

    /** php-src BcMath\Number::div — dividend scale when $scale is null. */
    protected function effectiveDivScale(\PHPCompiler\VM\ObjectEntry $receiver, ?int $scale): int
    {
        if (null !== $scale) {
            return $scale;
        }

        return VmBcMathNumber::objectScale($receiver);
    }

    /** php-src BcMath\Number::mod — max operand scale when $scale is null. */
    protected function effectiveModScale(\PHPCompiler\VM\ObjectEntry $receiver, string $right, ?int $scale): int
    {
        if (null !== $scale) {
            return $scale;
        }

        return max(VmBcMathNumber::objectScale($receiver), VmBcmath::decimalScale($right));
    }

    /**
     * php-src BcMath\Number::pow — receiver scale × integer exponent when $scale is null
     * (BC_MATH_NUMBER_EXPAND_SCALE path for negative exponents not used here).
     */
    protected function effectivePowScale(\PHPCompiler\VM\ObjectEntry $receiver, string $exponent, ?int $scale): int
    {
        if (null !== $scale) {
            return $scale;
        }
        $expo = (int) $exponent;
        if ($expo <= 0) {
            return 0;
        }
        $baseScale = VmBcMathNumber::objectScale($receiver);
        $product = $baseScale * $expo;
        if ($product < $baseScale) {
            throw new \ValueError('scale of the result is too large');
        }

        return $product;
    }

    /**
     * php-src BcMath\Number::sqrt — receiver scale + BC_MATH_NUMBER_EXPAND_SCALE (10) when null.
     *
     * @return array{0: int, 1: bool} requested scale and whether auto-expand applies
     */
    protected function effectiveSqrtScale(\PHPCompiler\VM\ObjectEntry $receiver, ?int $scale): array
    {
        if (null !== $scale) {
            return [$scale, false];
        }

        return [VmBcMathNumber::objectScale($receiver) + VmBcMathNumber::EXPAND_SCALE, true];
    }

    /**
     * After auto-expand sqrt/pow, shrink object scale by unused trailing zeros (php-src bcmath.c).
     */
    protected function shrinkAutoExpandScale(string $result, int $requestedScale): int
    {
        $resultScale = VmBcmath::decimalScale($result);
        $diff = $requestedScale - $resultScale;
        if ($diff <= 0) {
            return $requestedScale;
        }

        return $requestedScale - min($diff, VmBcMathNumber::EXPAND_SCALE);
    }

    /** php-src bc_rm_trailing_zeros — drop fractional trailing zeros from a decimal string. */
    protected function stripTrailingFracZeros(string $num): string
    {
        if (!str_contains($num, '.')) {
            return $num;
        }
        $num = rtrim($num, '0');

        return rtrim($num, '.') ?: '0';
    }

    protected function coerceOperand(Frame $frame, int $index, string $method, string $paramName): string
    {
        return VmBcMathNumber::coerceOperand($frame->calledArgs[$index], $method, $index, $paramName);
    }
}
