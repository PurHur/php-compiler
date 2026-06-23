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
            throw new \TypeError(self::message($function, $argIndex, $paramName, 'int', $arg));
        }

        return $arg;
    }

    public static function requireBool(Frame $frame, int $argIndex, string $function, string $paramName): Variable
    {
        $arg = $frame->calledArgs[$argIndex]->resolveIndirect();
        if (!self::callerStrict($frame)) {
            return $arg;
        }
        if (Variable::TYPE_BOOLEAN !== $arg->type) {
            throw new \TypeError(self::message($function, $argIndex, $paramName, 'bool', $arg));
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
            throw new \TypeError(self::message($function, $argIndex, $paramName, 'string', $arg));
        }

        return $arg;
    }

    /**
     * Reject null for internal string parameters (Zend ZEND_VERIFY_NULL_NOT_ALLOWED; #4365).
     */
    public static function rejectNullString(Variable $arg, string $function, string $paramName, int $argIndex = 0): void
    {
        $v = $arg->resolveIndirect();
        if (Variable::TYPE_NULL === $v->type) {
            throw new \TypeError(self::message($function, $argIndex, $paramName, 'string', $v));
        }
    }

    private static function callerStrict(Frame $frame): bool
    {
        return self::isCallerStrict($frame);
    }

    public static function isCallerStrict(Frame $frame): bool
    {
        return null !== $frame->parent && $frame->parent->block->strictTypes;
    }

    private static function message(
        string $function,
        int $argIndex,
        string $paramName,
        string $expected,
        Variable $arg
    ): string {
        return sprintf(
            '%s(): Argument #%d ($%s) must be of type %s, %s given',
            $function,
            $argIndex + 1,
            $paramName,
            $expected,
            EnumCaseSupport::typeNameForVariable($arg)
        );
    }
}
