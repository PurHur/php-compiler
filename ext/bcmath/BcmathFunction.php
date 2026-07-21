<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmRoundMode;
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
        return VmString::coerceStringBuiltinArg($frame->calledArgs[$index], $this->getName(), $index, $label);
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

    protected function maxArgCount(): int
    {
        return CompilerVersion::supportsRoundingModeEnum() ? 4 : 3;
    }

    protected function requireBinaryArgCount(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        $maxArgs = $this->maxArgCount();
        if ($argc < 2 || $argc > $maxArgs) {
            throw new \LogicException(
                4 === $maxArgs
                    ? $this->getName().'() requires two to four arguments in this compiler build'
                    : $this->getName().'() requires two or three arguments in this compiler build'
            );
        }
    }

    protected function optionalRoundingMode(Frame $frame, int $index): ?int
    {
        if (!isset($frame->calledArgs[$index])) {
            return null;
        }

        return VmRoundMode::resolveRoundModeArg(
            $frame->calledArgs[$index]->resolveIndirect(),
            $this->getName(),
            'rounding_mode',
            $index + 1
        );
    }

    protected function requireTernaryArgCount(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        $maxArgs = CompilerVersion::supportsRoundingModeEnum() ? 5 : 4;
        if ($argc < 3 || $argc > $maxArgs) {
            throw new \LogicException(
                5 === $maxArgs
                    ? $this->getName().'() requires three to five arguments in this compiler build'
                    : $this->getName().'() requires three or four arguments in this compiler build'
            );
        }
    }
}
