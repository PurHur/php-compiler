<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;

/**
 * Strict call-site checks for internal JIT builtins (issue #4332, zend_verify_arg_type parity).
 */
final class InternalStrictArg
{
    public static function requireInt(
        Context $context,
        Variable $arg,
        string $function,
        string $paramName,
        int $argNumber
    ): void {
        if (!$context->callerStrictTypes) {
            return;
        }
        if (Variable::TYPE_NATIVE_LONG === $arg->type) {
            return;
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            self::enforceExactValueBox($context, $arg, Variable::TYPE_NATIVE_LONG, $function, $paramName, $argNumber, 'int');

            return;
        }
        JitNativeString::ensureInsertBlock($context);
        self::raiseTypeErrorAndAbort(
            $context,
            self::message($context, $function, $argNumber, $paramName, 'int', $arg)
        );
    }

    /**
     * Builtin signature int — always reject non-int operands (php-src ZEND_ARG_INFO IS_LONG; #12215).
     */
    public static function requireBuiltinTypedInt(
        Context $context,
        Variable $arg,
        string $function,
        string $paramName,
        int $argNumber
    ): void {
        if (Variable::TYPE_NATIVE_LONG === $arg->type) {
            return;
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            self::enforceExactValueBox(
                $context,
                $arg,
                VmVariable::TYPE_INTEGER,
                $function,
                $paramName,
                $argNumber,
                'int'
            );

            return;
        }
        self::raiseTypeErrorAndAbort(
            $context,
            self::message($context, $function, $argNumber, $paramName, 'int', $arg)
        );
    }

    /** float builtin args: int widens; string rejected under caller strict_types (#11497). */
    public static function requireFloat(
        Context $context,
        Variable $arg,
        string $function,
        string $paramName,
        int $argNumber
    ): void {
        if (!$context->callerStrictTypes) {
            return;
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type || JITVariable::TYPE_NATIVE_DOUBLE === $arg->type) {
            return;
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            self::enforceFloatValueBox($context, $arg, $function, $paramName, $argNumber);

            return;
        }
        self::raiseTypeErrorAndAbort(
            $context,
            self::message($context, $function, $argNumber, $paramName, 'float', $arg)
        );
    }

    /**
     * Reject null for internal string parameters when caller uses strict_types (#4365, #11322).
     */
    public static function rejectNullString(
        Context $context,
        Variable $arg,
        string $function,
        string $paramName,
        int $argNumber
    ): void {
        if (!$context->callerStrictTypes) {
            return;
        }
        if (Variable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            self::raiseTypeErrorAndAbort(
                $context,
                self::message($context, $function, $argNumber, $paramName, 'string', $arg)
            );

            return;
        }
        if (Variable::TYPE_VALUE !== $arg->type) {
            return;
        }
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $okBlock = BasicBlockHelper::append($context, 'internal_reject_null_str_ok');
        $failBlock = BasicBlockHelper::append($context, 'internal_reject_null_str_fail');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(VmVariable::TYPE_NULL, false)
            ),
            $failBlock,
            $okBlock
        );
        $context->builder->positionAtEnd($failBlock);
        self::raiseTypeErrorAndAbort(
            $context,
            self::message($context, $function, $argNumber, $paramName, 'string', $arg)
        );
        $context->builder->positionAtEnd($okBlock);
    }

    /** Reject null for internal int parameters (Zend ZEND_VERIFY_NULL_NOT_ALLOWED). */
    public static function rejectNullInt(
        Context $context,
        Variable $arg,
        string $function,
        string $paramName,
        int $argNumber
    ): void {
        if (Variable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            self::raiseTypeErrorAndAbort(
                $context,
                self::message($context, $function, $argNumber, $paramName, 'int', $arg)
            );

            return;
        }
        if (Variable::TYPE_VALUE !== $arg->type) {
            return;
        }
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $okBlock = BasicBlockHelper::append($context, 'internal_reject_null_int_ok');
        $failBlock = BasicBlockHelper::append($context, 'internal_reject_null_int_fail');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(VmVariable::TYPE_NULL, false)
            ),
            $failBlock,
            $okBlock
        );
        $context->builder->positionAtEnd($failBlock);
        self::raiseTypeErrorAndAbort(
            $context,
            self::message($context, $function, $argNumber, $paramName, 'int', $arg)
        );
        $context->builder->positionAtEnd($okBlock);
    }

    /** Reject null for internal bool parameters (Zend ZEND_VERIFY_NULL_NOT_ALLOWED). */
    public static function rejectNullBool(
        Context $context,
        Variable $arg,
        string $function,
        string $paramName,
        int $argNumber
    ): void {
        if (Variable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            self::raiseTypeErrorAndAbort(
                $context,
                self::message($context, $function, $argNumber, $paramName, 'bool', $arg)
            );

            return;
        }
        if (Variable::TYPE_VALUE !== $arg->type) {
            return;
        }
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $okBlock = BasicBlockHelper::append($context, 'internal_reject_null_bool_ok');
        $failBlock = BasicBlockHelper::append($context, 'internal_reject_null_bool_fail');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(VmVariable::TYPE_NULL, false)
            ),
            $failBlock,
            $okBlock
        );
        $context->builder->positionAtEnd($failBlock);
        self::raiseTypeErrorAndAbort(
            $context,
            self::message($context, $function, $argNumber, $paramName, 'bool', $arg)
        );
        $context->builder->positionAtEnd($okBlock);
    }

    /**
     * Reject null for array|string internal parameters when caller uses strict_types (#11015).
     */
    public static function rejectNullStringOrArray(
        Context $context,
        Variable $arg,
        string $function,
        string $paramName,
        int $argNumber
    ): void {
        if (!$context->callerStrictTypes) {
            return;
        }
        if (Variable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            self::raiseTypeErrorAndAbort(
                $context,
                \sprintf(
                    '%s(): Argument #%d ($%s) must be of type array|string, null given',
                    $function,
                    $argNumber,
                    $paramName
                )
            );

            return;
        }
        if (Variable::TYPE_VALUE !== $arg->type) {
            return;
        }
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $okBlock = BasicBlockHelper::append($context, 'internal_reject_null_strarr_ok');
        $failBlock = BasicBlockHelper::append($context, 'internal_reject_null_strarr_fail');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(VmVariable::TYPE_NULL, false)
            ),
            $failBlock,
            $okBlock
        );
        $context->builder->positionAtEnd($failBlock);
        self::raiseTypeErrorAndAbort(
            $context,
            \sprintf(
                '%s(): Argument #%d ($%s) must be of type array|string, null given',
                $function,
                $argNumber,
                $paramName
            )
        );
        $context->builder->positionAtEnd($okBlock);
    }

    /**
     * Builtin signature bool — always reject non-bool operands (php-src ZEND_ARG_INFO IS_BOOL; #12585, #12586).
     */
    public static function requireBuiltinTypedBool(
        Context $context,
        Variable $arg,
        string $function,
        string $paramName,
        int $argNumber
    ): void {
        if (Variable::TYPE_NATIVE_BOOL === $arg->type) {
            return;
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            self::enforceExactValueBox(
                $context,
                $arg,
                VmVariable::TYPE_BOOLEAN,
                $function,
                $paramName,
                $argNumber,
                'bool'
            );

            return;
        }
        self::raiseTypeErrorAndAbort(
            $context,
            self::message($context, $function, $argNumber, $paramName, 'bool', $arg)
        );
    }

    public static function requireBool(
        Context $context,
        Variable $arg,
        string $function,
        string $paramName,
        int $argNumber
    ): void {
        if (!$context->callerStrictTypes) {
            return;
        }
        if (Variable::TYPE_NATIVE_BOOL === $arg->type) {
            return;
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            self::enforceExactValueBox(
                $context,
                $arg,
                VmVariable::TYPE_BOOLEAN,
                $function,
                $paramName,
                $argNumber,
                'bool'
            );

            return;
        }
        self::raiseTypeErrorAndAbort(
            $context,
            self::message($context, $function, $argNumber, $paramName, 'bool', $arg)
        );
    }

    public static function requireString(
        Context $context,
        Variable $arg,
        string $function,
        string $paramName,
        int $argNumber
    ): void {
        if (!$context->callerStrictTypes) {
            return;
        }
        if (Variable::TYPE_STRING === $arg->type) {
            return;
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            self::enforceExactValueBox($context, $arg, Variable::TYPE_STRING, $function, $paramName, $argNumber, 'string');

            return;
        }
        JitNativeString::ensureInsertBlock($context);
        self::raiseTypeErrorAndAbort(
            $context,
            self::message($context, $function, $argNumber, $paramName, 'string', $arg)
        );
    }

    private static function enforceExactValueBox(
        Context $context,
        Variable $arg,
        int $expected,
        string $function,
        string $paramName,
        int $argNumber,
        string $expectedLabel
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $okBlock = BasicBlockHelper::append($context, 'internal_strict_ok');
        $failBlock = BasicBlockHelper::append($context, 'internal_strict_fail');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt($expected, false)),
            $okBlock,
            $failBlock
        );
        $context->builder->positionAtEnd($failBlock);
        ExceptionBridge::emitTypeErrorAndAbort(
            $context,
            sprintf(
                '%s(): Argument #%d ($%s) must be of type %s, %s given',
                $function,
                $argNumber,
                $paramName,
                $expectedLabel,
                'mixed'
            )
        );
        $context->builder->positionAtEnd($okBlock);
    }

    private static function enforceFloatValueBox(
        Context $context,
        Variable $arg,
        string $function,
        string $paramName,
        int $argNumber
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $okBlock = BasicBlockHelper::append($context, 'internal_strict_float_ok');
        $failBlock = BasicBlockHelper::append($context, 'internal_strict_float_fail');
        $isInt = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_INTEGER, false)
        );
        $isFloat = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_FLOAT, false)
        );
        $isOk = $context->builder->or($isInt, $isFloat);
        $context->builder->branchIf($isOk, $okBlock, $failBlock);
        $context->builder->positionAtEnd($failBlock);
        ExceptionBridge::emitTypeErrorAndAbort(
            $context,
            sprintf(
                '%s(): Argument #%d ($%s) must be of type float, %s given',
                $function,
                $argNumber,
                $paramName,
                'mixed'
            )
        );
        $context->builder->positionAtEnd($okBlock);
    }

    private static function raiseTypeErrorAndAbort(Context $context, string $message): void
    {
        ExceptionBridge::emitTypeErrorAndAbort($context, $message);
    }

    private static function message(
        Context $context,
        string $function,
        int $argNumber,
        string $paramName,
        string $expected,
        Variable $arg
    ): string {
        return sprintf(
            '%s(): Argument #%d ($%s) must be of type %s, %s given',
            $function,
            $argNumber,
            $paramName,
            $expected,
            JitOperandTypeLabel::givenLabel($context, $arg)
        );
    }
}
