<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\IterableCheck;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Iterable builtin operand guards — TypeError before backing coercion (#6232, php-src-strict).
 *
 * php-src: ext/spl/php_spl.c — iterator_count / iterator_apply iterable checks
 */
final class JitIterableArg
{
    public static function iterableTypeErrorMessage(
        string $function,
        int $argIndex,
        string $paramName,
        string $given,
        bool $allowArray = true
    ): string {
        return \sprintf(
            '%s(): Argument #%d ($%s) must be of type %s, %s given',
            $function,
            $argIndex + 1,
            $paramName,
            $allowArray ? IterableCheck::TYPE_LABEL : IterableCheck::TRAVERSABLE_TYPE_LABEL,
            $given
        );
    }

    public static function emitIterableTypeErrorAndAbort(
        Context $context,
        string $function,
        int $argIndex,
        string $paramName,
        string $given,
        bool $allowArray = true
    ): void {
        // Catchable under AOT try/catch; fatal when uncaught (#27633 / peer #27511).
        ExceptionBridge::emitTypeErrorAndAbort(
            $context,
            self::iterableTypeErrorMessage($function, $argIndex, $paramName, $given, $allowArray)
        );
        BasicBlockHelper::ensureOpenInsertBlock($context, 'iterable_arg_te_cont');
    }

    /**
     * Runtime TypeError actual label from a boxed `__value__` (true/false not bool) (#30117).
     */
    public static function emitIterableTypeErrorFromValueBoxAndAbort(
        Context $context,
        string $function,
        int $argIndex,
        string $paramName,
        Value $valuePtr,
        bool $allowArray = true
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));

        $nullBb = BasicBlockHelper::append($context, 'iterable_te_null');
        $afterNull = BasicBlockHelper::append($context, 'iterable_te_after_null');
        $intBb = BasicBlockHelper::append($context, 'iterable_te_int');
        $afterInt = BasicBlockHelper::append($context, 'iterable_te_after_int');
        $floatBb = BasicBlockHelper::append($context, 'iterable_te_float');
        $afterFloat = BasicBlockHelper::append($context, 'iterable_te_after_float');
        $boolBb = BasicBlockHelper::append($context, 'iterable_te_bool');
        $afterBool = BasicBlockHelper::append($context, 'iterable_te_after_bool');
        $stringBb = BasicBlockHelper::append($context, 'iterable_te_string');
        $mixedBb = BasicBlockHelper::append($context, 'iterable_te_mixed');

        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(VmVariable::TYPE_NULL & 0x7f, false)
        );
        $context->builder->branchIf($isNull, $nullBb, $afterNull);
        $context->builder->positionAtEnd($nullBb);
        self::emitIterableTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'null', $allowArray);

        $context->builder->positionAtEnd($afterNull);
        $isInt = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(VmVariable::TYPE_INTEGER & 0x7f, false)
        );
        $context->builder->branchIf($isInt, $intBb, $afterInt);
        $context->builder->positionAtEnd($intBb);
        self::emitIterableTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'int', $allowArray);

        $context->builder->positionAtEnd($afterInt);
        $isFloat = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(VmVariable::TYPE_FLOAT & 0x7f, false)
        );
        $context->builder->branchIf($isFloat, $floatBb, $afterFloat);
        $context->builder->positionAtEnd($floatBb);
        self::emitIterableTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'float', $allowArray);

        $context->builder->positionAtEnd($afterFloat);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(VmVariable::TYPE_BOOLEAN & 0x7f, false)
        );
        $context->builder->branchIf($isBool, $boolBb, $afterBool);
        // zend_execute.c — bool actuals print true/false (#30117 / #29097).
        $context->builder->positionAtEnd($boolBb);
        $boolByte = JitValueBox::readBoolByte($context, $valuePtr);
        $isTrue = $context->builder->icmp(Builder::INT_NE, $boolByte, $i8->constInt(0, false));
        $trueBb = BasicBlockHelper::append($context, 'iterable_te_true');
        $falseBb = BasicBlockHelper::append($context, 'iterable_te_false');
        $context->builder->branchIf($isTrue, $trueBb, $falseBb);
        $context->builder->positionAtEnd($trueBb);
        self::emitIterableTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'true', $allowArray);
        $context->builder->positionAtEnd($falseBb);
        self::emitIterableTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'false', $allowArray);

        $context->builder->positionAtEnd($afterBool);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(VmVariable::TYPE_STRING & 0x7f, false)
        );
        $context->builder->branchIf($isString, $stringBb, $mixedBb);
        $context->builder->positionAtEnd($stringBb);
        self::emitIterableTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'string', $allowArray);

        $context->builder->positionAtEnd($mixedBb);
        self::emitIterableTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'mixed', $allowArray);
    }

    /**
     * Compile-time operand TypeError — native bools print true/false via IR (#30117).
     */
    public static function emitIterableTypeErrorForOperandAndAbort(
        Context $context,
        string $function,
        int $argIndex,
        string $paramName,
        Variable $arg,
        bool $allowArray = true
    ): void {
        if (Variable::TYPE_NATIVE_BOOL === $arg->type) {
            $boolVal = $arg->value;
            $isTrue = $context->builder->icmp(
                Builder::INT_NE,
                $boolVal,
                $boolVal->typeOf()->constInt(0, false)
            );
            $trueBb = BasicBlockHelper::append($context, 'iterable_native_true');
            $falseBb = BasicBlockHelper::append($context, 'iterable_native_false');
            $context->builder->branchIf($isTrue, $trueBb, $falseBb);
            $context->builder->positionAtEnd($trueBb);
            self::emitIterableTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'true', $allowArray);
            $context->builder->positionAtEnd($falseBb);
            self::emitIterableTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'false', $allowArray);

            return;
        }
        if (Variable::TYPE_VALUE === $arg->type || JitValueBox::isValueOperand($arg)) {
            self::emitIterableTypeErrorFromValueBoxAndAbort(
                $context,
                $function,
                $argIndex,
                $paramName,
                JitValueBox::valuePtrFromVariable($context, $arg),
                $allowArray
            );

            return;
        }
        self::emitIterableTypeErrorAndAbort(
            $context,
            $function,
            $argIndex,
            $paramName,
            JitOperandTypeLabel::givenLabel($context, $arg),
            $allowArray
        );
    }

    /**
     * Reject non-iterable operands at JIT lowering time (enum cases name enum class, not mixed).
     *
     * @return bool false when compile-time rejection IR was emitted (caller must not continue lowering)
     */
    public static function guardIterableOperand(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex = 0,
        string $paramName = 'iterator'
    ): bool {
        return self::guardOperand($context, $arg, $function, $argIndex, $paramName, true);
    }

    /**
     * iterator_apply() — Traversable only; arrays TypeError like Zend (#19839).
     *
     * @return bool false when compile-time rejection IR was emitted
     */
    public static function guardTraversableOperand(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex = 0,
        string $paramName = 'iterator'
    ): bool {
        return self::guardOperand($context, $arg, $function, $argIndex, $paramName, false);
    }

    private static function guardOperand(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName,
        bool $allowArray
    ): bool {
        if (self::isCompileTimeAccepted($context, $arg, $allowArray)) {
            return true;
        }
        $enumLabel = JitOperandTypeLabel::compileTimeEnumClassName($context, $arg);
        if (null !== $enumLabel) {
            self::emitIterableTypeErrorAndAbort($context, $function, $argIndex, $paramName, $enumLabel, $allowArray);

            return false;
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return self::guardValueBoxOperand($context, $arg, $function, $argIndex, $paramName, $allowArray);
        }
        if (Variable::TYPE_OBJECT === $arg->type) {
            self::emitIterableTypeErrorForOperandAndAbort(
                $context,
                $function,
                $argIndex,
                $paramName,
                $arg,
                $allowArray
            );

            return false;
        }
        self::emitIterableTypeErrorForOperandAndAbort(
            $context,
            $function,
            $argIndex,
            $paramName,
            $arg,
            $allowArray
        );

        return false;
    }

    private static function isCompileTimeAccepted(Context $context, Variable $arg, bool $allowArray): bool
    {
        if ($arg->type & Variable::IS_NATIVE_ARRAY || Variable::TYPE_HASHTABLE === $arg->type) {
            return $allowArray;
        }
        if (GeneratorHelper::isGeneratorVariable($arg)) {
            return true;
        }
        if (IteratorProtocolHelper::canLowerIteratorProtocol($context, $arg, null)) {
            return true;
        }
        // TYPE_VALUE + `__object__*` (nested `new` call args) — re-probe as object (#30273).
        if (Variable::TYPE_VALUE === $arg->type && Variable::KIND_VALUE === $arg->kind) {
            $llvmType = $context->getStringFromType($arg->value->typeOf());
            if ('__object__*' === $llvmType) {
                $asObj = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $arg->value);
                if (IteratorProtocolHelper::canLowerIteratorProtocol($context, $asObj, null)) {
                    return true;
                }
            }
        }
        if (Variable::TYPE_VALUE !== $arg->type) {
            return false;
        }
        $constantType = self::constantValueBoxType($context, $arg);
        if (null === $constantType) {
            return false;
        }
        if (VmVariable::TYPE_ARRAY === $constantType) {
            return $allowArray;
        }
        if (VmVariable::TYPE_OBJECT === $constantType) {
            return GeneratorHelper::isGeneratorVariable($arg)
                || IteratorProtocolHelper::canLowerIteratorProtocol($context, $arg, null);
        }

        return false;
    }

    private static function constantValueBoxType(Context $context, Variable $arg): ?int
    {
        if (Variable::KIND_VALUE !== $arg->kind) {
            return null;
        }
        $map = $context->structFieldMap['__value__'] ?? null;
        if (null === $map || !isset($map['type'])) {
            return null;
        }
        // Nested `new` / NestedJIT call args are often `__value__**` or `__object__*` while
        // still KIND_VALUE+TYPE_VALUE — never structGep a non-`__value__` receiver (#30273 / #21041).
        $valuePtr = self::valueBoxPtrIfPresent($context, $arg);
        if (null === $valuePtr) {
            return null;
        }
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        if (!method_exists($typeByte, 'isConstant') || !$typeByte->isConstant()) {
            return null;
        }
        if (!method_exists($typeByte, 'getConstantValue')) {
            return null;
        }

        return (int) $typeByte->getConstantValue();
    }

    /**
     * Compile-time peek only — null when {@see Variable::$value} is not a value-box pointer
     * (nested constructor results are often {@see __object__*}).
     */
    private static function valueBoxPtrIfPresent(Context $context, Variable $arg): ?Value
    {
        $tyName = $context->getStringFromType($arg->value->typeOf());
        // Only direct `__value__*` can yield a compile-time constant type tag.
        // `__value__**` / `__object__*` need a runtime load — return null (#30273).
        if ('__value__*' === $tyName) {
            return $arg->value;
        }
        if ('__value__' === $tyName) {
            return JitValueBox::pointer($context, $arg->value);
        }

        return null;
    }

    private static function guardValueBoxOperand(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName,
        bool $allowArray
    ): bool {
        $constantType = self::constantValueBoxType($context, $arg);
        if (null !== $constantType) {
            if (VmVariable::TYPE_ARRAY === $constantType) {
                if ($allowArray) {
                    return true;
                }
                self::emitIterableTypeErrorAndAbort(
                $context,
                $function,
                $argIndex,
                $paramName,
                'array',
                false
            );

                return false;
            }
            if (VmVariable::TYPE_ENUM_CASE === $constantType
                || VmVariable::TYPE_OBJECT === $constantType) {
                $enumLabel = JitOperandTypeLabel::compileTimeEnumClassName($context, $arg)
                    ?? JitOperandTypeLabel::givenLabel($context, $arg);
                self::emitIterableTypeErrorAndAbort($context, $function, $argIndex, $paramName, $enumLabel, $allowArray);

                return false;
            }
            self::emitIterableTypeErrorForOperandAndAbort(
                $context,
                $function,
                $argIndex,
                $paramName,
                $arg,
                $allowArray
            );

            return false;
        }

        self::emitRuntimeValueBoxEnumGuard($context, $arg, $function, $argIndex, $paramName, $allowArray);

        return true;
    }

    private static function emitRuntimeValueBoxEnumGuard(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName,
        bool $allowArray
    ): void {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $arrayTy = $i8->constInt(VmVariable::TYPE_ARRAY, false);
        $enumCaseTy = $i8->constInt(VmVariable::TYPE_ENUM_CASE, false);
        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeByte, $arrayTy);
        $okBlock = BasicBlockHelper::append($context, 'iterable_arg_ok');
        $checkEnumBlock = BasicBlockHelper::append($context, 'iterable_arg_check_enum');
        if ($allowArray) {
            $context->builder->branchIf($isArray, $okBlock, $checkEnumBlock);
        } else {
            $arrayReject = BasicBlockHelper::append($context, 'traversable_arg_array');
            $context->builder->branchIf($isArray, $arrayReject, $checkEnumBlock);
            $context->builder->positionAtEnd($arrayReject);
            self::emitIterableTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'array', false);
        }

        $context->builder->positionAtEnd($checkEnumBlock);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeByte, $enumCaseTy);
        $enumBlock = BasicBlockHelper::append($context, 'iterable_arg_enum');
        $context->builder->branchIf($isEnumCase, $enumBlock, $okBlock);

        $context->builder->positionAtEnd($enumBlock);
        self::emitIterableTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'object', $allowArray);

        $context->builder->positionAtEnd($okBlock);
    }
}

