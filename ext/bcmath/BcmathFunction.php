<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/**
 * Shared VM wiring for bcmath builtins (php-src ext/bcmath/bcmath.c; issue #3365).
 */
abstract class BcmathFunction extends Internal
{
    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $this->writeReturn($frame, $this->compute($frame));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() is not implemented for JIT in this compiler build (issue #3365)');
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
        if (\count($frame->calledArgs) <= $index) {
            return null;
        }
        $var = $frame->calledArgs[$index]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \LogicException($this->getName().'() scale must be an integer in this compiler build');
        }

        return $var->toInt();
    }
}
