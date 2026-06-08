<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for intdiv() operand coercion (php-src math.c; #4982, #5360). */
final class JitIntdiv
{
    public static function lowerOperands(Context $context, JITVariable $num1, JITVariable $num2): array
    {
        return [
            self::lowerIntBuiltinArg($context, $num1, 'intdiv', 1, 'num1'),
            self::lowerIntBuiltinArg($context, $num2, 'intdiv', 2, 'num2'),
        ];
    }

    /**
     * Z_PARAM_LONG-style operand lowering (php-src math.c; shared by intdiv/dechex/decbin/decoct).
     */
    public static function lowerIntBuiltinArg(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): Value {
        return self::lowerIntOperand($context, $arg, $argIndex, $paramName, $function);
    }

    private static function lowerIntOperand(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName,
        string $function
    ): Value {
        if (JITVariable::TYPE_NULL === $arg->type) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (($arg->type & JITVariable::IS_NATIVE_ARRAY) || JITVariable::TYPE_HASHTABLE === $arg->type) {
            self::emitIntTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'array');

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            self::emitIntTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'object');

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE === $arg->type) {
            return self::lowerNativeDoubleOperand($context, $arg, $argIndex, $paramName, $function);
        }
        if (JITVariable::TYPE_STRING === $arg->type) {
            return self::lowerStringOperand($context, $arg, $argIndex, $paramName, $function);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxedOperand($context, $arg, $argIndex, $paramName, $function);
        }

        return JitLongArg::lower($context, $arg, sprintf('%s() argument #%d', $function, $argIndex));
    }

    private static function lowerStringOperand(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName,
        string $function
    ): Value {
        $strPtr = JitStringArg::lower($context, $arg, sprintf('%s() argument #%d', $function, $argIndex));
        $isNumeric = self::stringPtrIsNumeric($context, $strPtr);
        $okBlock = BasicBlockHelper::append($context, 'intdiv_str_ok');
        $errBlock = BasicBlockHelper::append($context, 'intdiv_str_err');
        $context->builder->branchIf($isNumeric, $okBlock, $errBlock);
        $context->builder->positionAtEnd($errBlock);
        self::emitIntTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'string');
        $context->builder->positionAtEnd($okBlock);

        return self::stringPtrToLong($context, $strPtr);
    }

    private static function lowerBoxedOperand(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName,
        string $function
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
        $doubleTy = $i8->constInt(VmVariable::TYPE_NATIVE_DOUBLE, false);
        $stringTy = $i8->constInt(VmVariable::TYPE_STRING, false);

        $nullBlock = BasicBlockHelper::append($context, 'intdiv_box_null');
        $afterNull = BasicBlockHelper::append($context, 'intdiv_box_after_null');
        $arrayBlock = BasicBlockHelper::append($context, 'intdiv_box_array');
        $objectBlock = BasicBlockHelper::append($context, 'intdiv_box_object');
        $doubleBlock = BasicBlockHelper::append($context, 'intdiv_box_double');
        $stringBlock = BasicBlockHelper::append($context, 'intdiv_box_string');
        $coerceBlock = BasicBlockHelper::append($context, 'intdiv_box_coerce');
        $mergeBlock = BasicBlockHelper::append($context, 'intdiv_box_merge');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);

        $isNull = $context->builder->icmp(Builder::INT_EQ, $typeByte, $nullTy);
        $context->builder->branchIf($isNull, $nullBlock, $afterNull);

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($afterNull);
        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeByte, $arrayTy);
        $context->builder->branchIf($isArray, $arrayBlock, $objectBlock);

        $context->builder->positionAtEnd($arrayBlock);
        self::emitIntTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'array');

        $context->builder->positionAtEnd($objectBlock);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeByte, $objectTy);
        $errBlock = BasicBlockHelper::append($context, 'intdiv_box_object_err');
        $afterObject = BasicBlockHelper::append($context, 'intdiv_box_after_object');
        $context->builder->branchIf($isObject, $errBlock, $afterObject);

        $context->builder->positionAtEnd($errBlock);
        self::emitIntTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'object');

        $context->builder->positionAtEnd($afterObject);
        $isDouble = $context->builder->icmp(Builder::INT_EQ, $typeByte, $doubleTy);
        $context->builder->branchIf($isDouble, $doubleBlock, $stringBlock);

        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $valuePtr);
        $truncated = self::lowerFiniteDoubleToLong($context, $doubleVal, $function, $argIndex, $paramName);
        $doubleEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($stringBlock);
        $isString = $context->builder->icmp(Builder::INT_EQ, $typeByte, $stringTy);
        $stringCoerce = BasicBlockHelper::append($context, 'intdiv_box_string_coerce');
        $context->builder->branchIf($isString, $stringCoerce, $coerceBlock);

        $context->builder->positionAtEnd($stringCoerce);
        $strVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $strLong = self::lowerStringOperandFromPtr($context, $strVal, $function, $argIndex, $paramName);
        $stringEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($coerceBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $coerceEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($i64, 'intdiv_box_phi');
        $phi->addIncoming($zero, $nullBlock);
        $phi->addIncoming($truncated, $doubleEnd);
        $phi->addIncoming($strLong, $stringEnd);
        $phi->addIncoming($longVal, $coerceEnd);

        return $phi;
    }

    private static function lowerNativeDoubleOperand(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName,
        string $function
    ): Value {
        $doubleVal = $context->helper->loadValue($arg);

        return self::lowerFiniteDoubleToLong($context, $doubleVal, $function, $argIndex, $paramName);
    }

    private static function lowerFiniteDoubleToLong(
        Context $context,
        Value $doubleVal,
        string $function,
        int $argIndex,
        string $paramName
    ): Value {
        $i32 = $context->getTypeFromString('int32');
        $finite = $context->builder->call($context->lookupFunction('isfinite'), $doubleVal);
        $isFinite = $context->builder->icmp(
            Builder::INT_NE,
            $finite,
            $i32->constInt(0, false)
        );
        $okBlock = BasicBlockHelper::append($context, 'intdiv_dbl_ok');
        $errBlock = BasicBlockHelper::append($context, 'intdiv_dbl_err');
        $context->builder->branchIf($isFinite, $okBlock, $errBlock);
        $context->builder->positionAtEnd($errBlock);
        self::emitIntTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'float');
        $context->builder->positionAtEnd($okBlock);

        return $context->builder->fptosi($doubleVal, $context->getTypeFromString('int64'));
    }

    private static function lowerStringOperandFromPtr(
        Context $context,
        Value $strPtr,
        string $function,
        int $argIndex,
        string $paramName
    ): Value {
        $isNumeric = self::stringPtrIsNumeric($context, $strPtr);
        $okBlock = BasicBlockHelper::append($context, 'intdiv_box_str_ok');
        $errBlock = BasicBlockHelper::append($context, 'intdiv_box_str_err');
        $context->builder->branchIf($isNumeric, $okBlock, $errBlock);
        $context->builder->positionAtEnd($errBlock);
        self::emitIntTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'string');
        $context->builder->positionAtEnd($okBlock);

        return self::stringPtrToLong($context, $strPtr);
    }

    private static function stringPtrIsNumeric(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $i64->constInt(0, false));
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtrSlot = $context->builder->alloca($i8p, 1, 'intdiv_str_end');
        $context->builder->store($i8p->constNull(), $endPtrSlot);
        $context->builder->call(
            $context->lookupFunction('strtol'),
            $charPtr,
            $endPtrSlot,
            $context->getTypeFromString('int32')->constInt(10, false)
        );
        $endPtr = $context->builder->load($endPtrSlot);
        $endOffset = $context->builder->sub(
            $context->builder->ptrToInt($endPtr, $i64),
            $context->builder->ptrToInt($charPtr, $i64)
        );
        $consumed = $context->builder->icmp(Builder::INT_NE, $endOffset, $i64->constInt(0, false));

        return $context->builder->and(
            $context->builder->not($isEmpty),
            $consumed
        );
    }

    private static function stringPtrToLong(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtrSlot = $context->builder->alloca($context->getTypeFromString('int8*'), 1, 'intdiv_strtol_end');
        $context->builder->store($context->getTypeFromString('int8*')->constNull(), $endPtrSlot);
        $raw = $context->builder->call(
            $context->lookupFunction('strtol'),
            $charPtr,
            $endPtrSlot,
            $context->getTypeFromString('int32')->constInt(10, false)
        );

        return $context->builder->trunc($raw, $context->getTypeFromString('int64'));
    }

    private static function intTypeError(
        string $function,
        int $argIndex,
        string $paramName,
        string $given
    ): string {
        return sprintf(
            '%s(): Argument #%d ($%s) must be of type int, %s given',
            $function,
            $argIndex,
            $paramName,
            $given
        );
    }

    private static function emitIntTypeErrorAndAbort(
        Context $context,
        string $function,
        int $argIndex,
        string $paramName,
        string $given
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, self::intTypeError($function, $argIndex, $paramName, $given));
        $context->builder->call($context->lookupFunction('abort'));
    }
}
