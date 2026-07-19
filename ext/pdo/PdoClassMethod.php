<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pdo;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;

/** Shared VM wiring for ext/pdo class methods (php-src ext/pdo; #3367). */
abstract class PdoClassMethod extends VmClassMethod
{
    protected function receiver(Frame $frame, string $label): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($label.' called without $this');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf('%s must be called on an object, %s given', $label, self::typeLabel($var)));
        }

        return $var->toObject();
    }

    /**
     * Z_PARAM_STR — null TypeError on PROFILE=8.4 (php-src ext/pdo stubs; #21080).
     */
    protected function stringArg(Variable $var, string $label, int $index, string $paramName): string
    {
        return VmString::coerceZparamStrBuiltinArg($var, $label, $index, $paramName);
    }

    protected function intArg(Variable $var, string $label, int $index, string $paramName): int
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER === $resolved->type) {
            return $resolved->toInt();
        }
        if (Variable::TYPE_FLOAT === $resolved->type) {
            return (int) $resolved->toFloat();
        }
        if (Variable::TYPE_BOOLEAN === $resolved->type) {
            return $resolved->toBool() ? 1 : 0;
        }
        if (Variable::TYPE_STRING === $resolved->type && is_numeric($resolved->toString())) {
            return (int) $resolved->toString();
        }

        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($%s) must be of type int, %s given',
            $label,
            $index + 1,
            $paramName,
            self::typeLabel($var)
        ));
    }

    protected static function typeLabel(Variable $var): string
    {
        $resolved = $var->resolveIndirect();

        return match ($resolved->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $resolved->toObject()->class->name,
            Variable::TYPE_RESOURCE => 'resource',
            default => 'mixed',
        };
    }
}
