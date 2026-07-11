<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM ?int timestamp operands for date()/gmdate()/getdate() family (#5842). */
final class JitDateTimestampArg
{
    public static function lowerNullable(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex,
        string $paramName,
        Value $whenNull
    ): Value {
        if (JITVariable::TYPE_NULL === $arg->type) {
            return $whenNull;
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE === $arg->type) {
            if ($context->callerStrictTypes) {
                self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'float');

                return $whenNull;
            }

            return $context->builder->fpToSi(
                $context->helper->loadValue($arg),
                $context->getTypeFromString('int64')
            );
        }
        if (($arg->type & JITVariable::IS_NATIVE_ARRAY) || JITVariable::TYPE_HASHTABLE === $arg->type) {
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'array');

            return $whenNull;
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            self::emitTypeErrorAndAbort(
                $context,
                $function,
                $argIndex,
                $paramName,
                JitOperandTypeLabel::givenLabel($context, $arg)
            );

            return $whenNull;
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxedNullable($context, $arg, $function, $argIndex, $paramName, $whenNull);
        }

        self::emitTypeErrorAndAbort(
            $context,
            $function,
            $argIndex,
            $paramName,
            JitOperandTypeLabel::givenLabel($context, $arg)
        );

        return $whenNull;
    }

    private static function lowerBoxedNullable(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex,
        string $paramName,
        Value $whenNull
    ): Value {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $nullTy = $i8->constInt(VmVariable::TYPE_NULL, false);
        $arrayTy = $i8->constInt(VmVariable::TYPE_ARRAY, false);
        $objectTy = $i8->constInt(VmVariable::TYPE_OBJECT, false);
        $enumCaseTy = $i8->constInt(VmVariable::TYPE_ENUM_CASE, false);
        $intTy = $i8->constInt(VmVariable::TYPE_INTEGER, false);
        $floatTy = $i8->constInt(VmVariable::TYPE_FLOAT, false);

        $nullBlock = BasicBlockHelper::append($context, 'date_ts_null');
        $notNullBlock = BasicBlockHelper::append($context, 'date_ts_not_null');
        $arrayBlock = BasicBlockHelper::append($context, 'date_ts_array');
        $rejectBlock = BasicBlockHelper::append($context, 'date_ts_reject');
        $intBlock = BasicBlockHelper::append($context, 'date_ts_int');
        $floatBlock = BasicBlockHelper::append($context, 'date_ts_float');
        $mergeBlock = BasicBlockHelper::append($context, 'date_ts_merge');

        $isNull = $context->builder->icmp(Builder::INT_EQ, $typeByte, $nullTy);
        $context->builder->branchIf($isNull, $nullBlock, $notNullBlock);

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($notNullBlock);
        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeByte, $arrayTy);
        $context->builder->branchIf($isArray, $arrayBlock, $rejectBlock);

        $context->builder->positionAtEnd($arrayBlock);
        self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'array');

        $context->builder->positionAtEnd($rejectBlock);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeByte, $objectTy);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeByte, $enumCaseTy);
        $isObjOrEnum = $context->builder->or($isObject, $isEnumCase);
        $errBlock = BasicBlockHelper::append($context, 'date_ts_err');
        $classifyBlock = BasicBlockHelper::append($context, 'date_ts_classify');
        $context->builder->branchIf($isObjOrEnum, $errBlock, $classifyBlock);

        $context->builder->positionAtEnd($errBlock);
        self::emitTypeErrorAndAbort(
            $context,
            $function,
            $argIndex,
            $paramName,
            JitOperandTypeLabel::givenLabel($context, $arg)
        );

        $context->builder->positionAtEnd($classifyBlock);
        $isInt = $context->builder->icmp(Builder::INT_EQ, $typeByte, $intTy);
        $isFloat = $context->builder->icmp(Builder::INT_EQ, $typeByte, $floatTy);
        $isNumeric = $context->builder->or($isInt, $isFloat);
        $badBlock = BasicBlockHelper::append($context, 'date_ts_bad');
        $numericBlock = BasicBlockHelper::append($context, 'date_ts_numeric');
        $context->builder->branchIf($isNumeric, $numericBlock, $badBlock);

        $context->builder->positionAtEnd($badBlock);
        self::emitTypeErrorAndAbort(
            $context,
            $function,
            $argIndex,
            $paramName,
            JitOperandTypeLabel::givenLabel($context, $arg)
        );

        $context->builder->positionAtEnd($numericBlock);
        $context->builder->branchIf($isInt, $intBlock, $floatBlock);

        $context->builder->positionAtEnd($intBlock);
        $longVal = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valuePtr
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($floatBlock);
        if ($context->callerStrictTypes) {
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'float');
            $floatLong = $whenNull;
        } else {
            $doubleVal = $context->builder->call(
                $context->lookupFunction('__value__readDouble'),
                $valuePtr
            );
            $floatLong = $context->builder->fpToSi($doubleVal, $context->getTypeFromString('int64'));
        }
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($context->getTypeFromString('int64'));
        $phi->addIncoming($whenNull, $nullBlock);
        $phi->addIncoming($longVal, $intBlock);
        $phi->addIncoming($floatLong, $floatBlock);

        return $phi;
    }

    private static function emitTypeErrorAndAbort(
        Context $context,
        string $function,
        int $argIndex,
        string $paramName,
        string $given
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            VmDate::nullableTimestampTypeError($function, $argIndex, $paramName, $given)
        );
        $context->builder->call($context->lookupFunction('abort'));
    }
}
