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

    /**
     * Builtin signature int — always reject non-int operands (php-src ZEND_ARG_INFO IS_LONG; #12215).
     */
    public static function requireBuiltinTypedInt(
        Frame $frame,
        int $argIndex,
        string $function,
        string $paramName
    ): Variable {
        $arg = $frame->calledArgs[$argIndex]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $arg->type) {
            throw new \TypeError(self::message($function, $argIndex, $paramName, 'int', $arg));
        }

        return $arg;
    }

    /** Builtin signature bool — always reject non-bool operands (php-src ZEND_ARG_INFO IS_BOOL; #12585). */
    public static function requireBuiltinTypedBoolArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): bool {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_BOOLEAN !== $var->type) {
            throw new \TypeError(self::message($function, $argIndex, $paramName, 'bool', $var));
        }

        return $var->toBool();
    }

    /** Builtin signature ?bool — null or bool only (php-src nullable internal param; #12585). */
    public static function parseBuiltinNullableBoolArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): ?bool {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (Variable::TYPE_BOOLEAN === $var->type) {
            return $var->toBool();
        }

        throw new \TypeError(self::message($function, $argIndex, $paramName, 'bool', $var));
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

    /** float builtin args: int widens; string rejected under caller strict_types (#11497, zend_verify_arg_type). */
    public static function requireFloat(Frame $frame, int $argIndex, string $function, string $paramName): Variable
    {
        $arg = $frame->calledArgs[$argIndex]->resolveIndirect();
        if (!self::callerStrict($frame)) {
            return $arg;
        }
        if (Variable::TYPE_INTEGER === $arg->type || Variable::TYPE_FLOAT === $arg->type) {
            return $arg;
        }

        throw new \TypeError(self::message($function, $argIndex, $paramName, 'float', $arg));
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
     * Reject null for internal string parameters when caller uses strict_types (#4365, #11322).
     *
     * Non-strict callers coerce null to "" via VmString::coerceStringBuiltinArg (php-src Z_PARAM_STR).
     */
    public static function rejectNullString(
        Variable $arg,
        string $function,
        string $paramName,
        int $argIndex,
        Frame $frame
    ): void {
        if (!self::isCallerStrict($frame)) {
            return;
        }
        $v = $arg->resolveIndirect();
        if (Variable::TYPE_NULL === $v->type) {
            throw new \TypeError(self::message($function, $argIndex, $paramName, 'string', $v));
        }
    }

    /** Reject null for internal int parameters (Zend ZEND_VERIFY_NULL_NOT_ALLOWED). */
    public static function rejectNullInt(Variable $arg, string $function, string $paramName, int $argIndex = 0): void
    {
        $v = $arg->resolveIndirect();
        if (Variable::TYPE_NULL === $v->type) {
            throw new \TypeError(self::message($function, $argIndex, $paramName, 'int', $v));
        }
    }

    /** Reject null for internal bool parameters (Zend ZEND_VERIFY_NULL_NOT_ALLOWED). */
    public static function rejectNullBool(Variable $arg, string $function, string $paramName, int $argIndex = 0): void
    {
        $v = $arg->resolveIndirect();
        if (Variable::TYPE_NULL === $v->type) {
            throw new \TypeError(self::message($function, $argIndex, $paramName, 'bool', $v));
        }
    }

    private static function callerStrict(Frame $frame): bool
    {
        return self::isCallerStrict($frame);
    }

    public static function isCallerStrict(Frame $frame): bool
    {
        $walker = $frame->parent;
        while (null !== $walker) {
            if ($walker->block->strictTypes) {
                return true;
            }
            $walker = $walker->parent;
        }

        return false;
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
