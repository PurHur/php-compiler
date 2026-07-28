<?php

declare(strict_types=1);

namespace PHPCompiler\ext\opcache;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** Shared VM wiring for opcache builtins (php-src ext/opcache; issue #4421). */
abstract class OpcacheFunction extends Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() is not implemented for JIT in this compiler build (issue #4421)');
    }

    protected function optionalBoolArg(Frame $frame, int $index, bool $default, string $paramName = 'include_scripts'): bool
    {
        if (\count($frame->calledArgs) <= $index) {
            return $default;
        }
        $var = $frame->calledArgs[$index]->resolveIndirect();
        if (Variable::TYPE_BOOLEAN !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type bool, %s given',
                $this->getName(),
                $index + 1,
                $paramName,
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
