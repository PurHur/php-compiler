<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/**
 * Shared VM wiring for bcmath builtins (php-src ext/bcmath/bcmath.c; issue #3365).
 */
abstract class BcmathFunction extends Internal
{
    public function execute(Frame $frame): void
    {
        $result = $this->compute($frame);
        if (null === $frame->returnVar) {
            return;
        }
        $this->writeReturn($frame, $result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return match ($this->getName()) {
            'bcadd' => JitBcmath::add($context, ...$args),
            'bcsub' => JitBcmath::sub($context, ...$args),
            'bcmul' => JitBcmath::mul($context, ...$args),
            'bcdiv' => JitBcmath::div($context, ...$args),
            'bccomp' => JitBcmath::comp($context, ...$args),
            'bcmod' => JitBcmath::mod($context, ...$args),
            'bcpow' => JitBcmath::pow($context, ...$args),
            'bcsqrt' => JitBcmath::sqrt($context, ...$args),
            'bcpowmod' => JitBcmath::powmod($context, ...$args),
            'bcround' => JitBcmath::round($context, ...$args),
            default => throw new \LogicException('unsupported bcmath builtin: '.$this->getName()),
        };
    }

    abstract protected function compute(Frame $frame): string|int;

    protected function writeReturn(Frame $frame, string|int $result): void
    {
        if (\is_int($result)) {
            $frame->returnVar->int($result);

            return;
        }
        $frame->returnVar->string($result);
    }

    protected function requireStringArg(Frame $frame, int $index, string $label): string
    {
        // Z_PARAM_STR: caller strict_types → TypeError; else soft-null DEP+coerce (#29977, re-#28992).
        return VmString::stringBuiltinArgForFrame($frame, $index, $this->getName(), $index, $label);
    }

    protected function optionalScale(Frame $frame, int $index): ?int
    {
        if (!isset($frame->calledArgs[$index])) {
            return null;
        }

        return VmBcMathNumber::optionalScaleArg(
            $frame->calledArgs[$index],
            $this->getName(),
            $index + 1,
            $frame
        );
    }

    /**
     * php-src bcmath.stub.php — bcadd/bcsub/bcmul/bcdiv/bcmod are arity ≤3 (num1,num2,scale).
     * Only bcround takes RoundingMode; the 4th $rounding_mode was a phantom (#26143, reverts #9946/#9919).
     */
    protected function requireBinaryArgCount(Frame $frame): void
    {
        $this->requireArgCountRange($frame, $this->getName(), 2, 3);
    }

    /**
     * php-src — bcpowmod is arity ≤4 (num,exponent,modulus,scale); no RoundingMode (#26143).
     */
    protected function requireTernaryArgCount(Frame $frame): void
    {
        $this->requireArgCountRange($frame, $this->getName(), 3, 4);
    }
}
