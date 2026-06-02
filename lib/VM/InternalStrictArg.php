<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;

/**
 * Strict call-site checks for internal builtins (issue #4332, zend_verify_arg_type parity).
 */
final class InternalStrictArg
{
    public static function requireInt(Frame $frame, int $argIndex, string $function, string $paramName): Variable
    {
        $arg = $frame->calledArgs[$argIndex]->resolveIndirect();
        if (!self::callerStrict($frame)) {
            return $arg;
        }
        if (Variable::TYPE_INTEGER !== $arg->type) {
            throw new \TypeError(self::message($function, $argIndex, $paramName, 'int', $arg->type));
        }

        return $arg;
    }

    public static function requireString(Frame $frame, int $argIndex, string $function, string $paramName): Variable
    {
        $arg = $frame->calledArgs[$argIndex]->resolveIndirect();
        if (!self::callerStrict($frame)) {
            return $arg;
        }
        if (Variable::TYPE_STRING !== $arg->type) {
            throw new \TypeError(self::message($function, $argIndex, $paramName, 'string', $arg->type));
        }

        return $arg;
    }

    private static function callerStrict(Frame $frame): bool
    {
        return null !== $frame->parent && $frame->parent->block->strictTypes;
    }

    private static function message(
        string $function,
        int $argIndex,
        string $paramName,
        string $expected,
        int $givenType
    ): string {
        return sprintf(
            '%s(): Argument #%d ($%s) must be of type %s, %s given',
            $function,
            $argIndex + 1,
            $paramName,
            $expected,
            self::typeName($givenType)
        );
    }

    private static function typeName(int $type): string
    {
        return match ($type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }
}
