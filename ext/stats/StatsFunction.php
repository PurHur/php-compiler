<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** Shared VM wiring for stats builtins (PECL stats; issue #5748). */
abstract class StatsFunction extends Internal
{
    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $result = $this->compute($frame);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->float($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() is not implemented for JIT in this compiler build (issue #5748)');
    }

    /** @return float|false */
    abstract protected function compute(Frame $frame): float|bool;

    protected function requireArrayArg(Frame $frame, int $index, string $label): Variable
    {
        $var = $frame->calledArgs[$index]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type array, %s given',
                $this->getName(),
                $index + 1,
                $label,
                self::debugTypeName($var)
            ));
        }

        return $var;
    }

    protected function optionalSampleFlag(Frame $frame, int $index): bool
    {
        if (\count($frame->calledArgs) <= $index) {
            return false;
        }
        $var = $frame->calledArgs[$index]->resolveIndirect();
        if (Variable::TYPE_BOOLEAN !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($sample) must be of type bool, %s given',
                $this->getName(),
                $index + 1,
                self::debugTypeName($var)
            ));
        }

        return $var->toBool();
    }

    private static function debugTypeName(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }
}
