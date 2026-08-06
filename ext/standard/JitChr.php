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

/** LLVM lowering for chr() codepoint coercion (php-src string.c; #5085). */
final class JitChr
{
    public static function lowerCodepoint(Context $context, JITVariable $arg): Value
    {
        return self::lowerZParamLongArg($context, $arg, 'chr', 1, 'codepoint');
    }

    /** Z_PARAM_LONG with null deprecation on forward profile (chr/long2ip; #21222, #21236). */
    public static function lowerZParamLongArg(
        Context $context,
        JITVariable $arg,
        string $function,
        int $userArgIndex,
        string $paramName
    ): Value {
        if (JITVariable::TYPE_NULL === $arg->type) {
            if ($context->callerStrictTypes) {
                self::emitIntTypeErrorAndAbort($context, $function, $userArgIndex, $paramName, 'null');
            } else {
                JitIntdiv::emitNullIntDeprecation($context, $function, $userArgIndex, $paramName);
            }

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (($arg->type & JITVariable::IS_NATIVE_ARRAY) || JITVariable::TYPE_HASHTABLE === $arg->type) {
            self::emitIntTypeErrorAndAbort($context, $function, $userArgIndex, $paramName, 'array');

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            self::emitIntTypeErrorAndAbort(
                $context,
                $function,
                $userArgIndex,
                $paramName,
                self::compileTimeObjectGivenLabel($context, $arg)
            );

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE === $arg->type) {
            return JitIntdiv::floatToLongTypedSafe(
                $context,
                $context->helper->loadValue($arg),
                self::intTypeError($function, $userArgIndex, $paramName, 'float')
            );
        }
        if (JITVariable::TYPE_STRING === $arg->type) {
            return self::lowerStringOperand($context, $arg, $function, $userArgIndex, $paramName);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxedOperand($context, $arg, $function, $userArgIndex, $paramName);
        }

        return JitLongArg::lower($context, $arg, sprintf('%s() argument #%d', $function, $userArgIndex));
    }

    private static function lowerStringOperand(
        Context $context,
        JITVariable $arg,
        string $function,
        int $userArgIndex,
        string $paramName
    ): Value {
        $strPtr = JitStringArg::lower($context, $arg, sprintf('%s() argument #%d', $function, $userArgIndex));

        return self::lowerStringOperandFromPtr($context, $strPtr, $function, $userArgIndex, $paramName);
    }

    private static function lowerBoxedOperand(
        Context $context,
        JITVariable $arg,
        string $function,
        int $userArgIndex,
        string $paramName
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
        $doubleTy = $i8->constInt(VmVariable::TYPE_FLOAT, false);
        $stringTy = $i8->constInt(VmVariable::TYPE_STRING, false);

        $nullBlock = BasicBlockHelper::append($context, 'chr_box_null');
        $afterNull = BasicBlockHelper::append($context, 'chr_box_after_null');
        $arrayBlock = BasicBlockHelper::append($context, 'chr_box_array');
        $objectBlock = BasicBlockHelper::append($context, 'chr_box_object');
        $doubleBlock = BasicBlockHelper::append($context, 'chr_box_double');
        $stringBlock = BasicBlockHelper::append($context, 'chr_box_string');
        $coerceBlock = BasicBlockHelper::append($context, 'chr_box_coerce');
        $mergeBlock = BasicBlockHelper::append($context, 'chr_box_merge');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);

        $isNull = $context->builder->icmp(Builder::INT_EQ, $typeByte, $nullTy);
        $context->builder->branchIf($isNull, $nullBlock, $afterNull);

        $context->builder->positionAtEnd($nullBlock);
        if ($context->callerStrictTypes) {
            self::emitIntTypeErrorAndAbort($context, $function, $userArgIndex, $paramName, 'null');
        } else {
            JitIntdiv::emitNullIntDeprecation($context, $function, $userArgIndex, $paramName);
        }
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($afterNull);
        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeByte, $arrayTy);
        $context->builder->branchIf($isArray, $arrayBlock, $objectBlock);

        $context->builder->positionAtEnd($arrayBlock);
        self::emitIntTypeErrorAndAbort($context, $function, $userArgIndex, $paramName, 'array');

        $context->builder->positionAtEnd($objectBlock);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeByte, $objectTy);
        $errBlock = BasicBlockHelper::append($context, 'chr_box_object_err');
        $afterObject = BasicBlockHelper::append($context, 'chr_box_after_object');
        $context->builder->branchIf($isObject, $errBlock, $afterObject);

        $context->builder->positionAtEnd($errBlock);
        self::emitIntTypeErrorAndAbort($context, $function, $userArgIndex, $paramName, 'object');

        $context->builder->positionAtEnd($afterObject);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeByte, $enumCaseTy);
        $enumErrBlock = BasicBlockHelper::append($context, 'chr_box_enum_err');
        $afterEnum = BasicBlockHelper::append($context, 'chr_box_after_enum');
        $context->builder->branchIf($isEnumCase, $enumErrBlock, $afterEnum);

        $context->builder->positionAtEnd($enumErrBlock);
        self::emitIntTypeErrorAndAbort(
            $context,
            $function,
            $userArgIndex,
            $paramName,
            self::compileTimeEnumCaseGivenLabel($context, $arg)
        );

        $context->builder->positionAtEnd($afterEnum);
        $isDouble = $context->builder->icmp(Builder::INT_EQ, $typeByte, $doubleTy);
        $context->builder->branchIf($isDouble, $doubleBlock, $stringBlock);

        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $valuePtr);
        $truncated = JitIntdiv::floatToLongTypedSafe(
            $context,
            $doubleVal,
            self::intTypeError($function, $userArgIndex, $paramName, 'float')
        );
        $doubleEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($stringBlock);
        $isString = $context->builder->icmp(Builder::INT_EQ, $typeByte, $stringTy);
        $stringCoerce = BasicBlockHelper::append($context, 'chr_box_string_coerce');
        $context->builder->branchIf($isString, $stringCoerce, $coerceBlock);

        $context->builder->positionAtEnd($stringCoerce);
        $strVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $strLong = self::lowerStringOperandFromPtr($context, $strVal, $function, $userArgIndex, $paramName);
        $stringEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($coerceBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $coerceEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($i64, 'chr_box_phi');
        $phi->addIncoming($zero, $nullBlock);
        $phi->addIncoming($truncated, $doubleEnd);
        $phi->addIncoming($strLong, $stringEnd);
        $phi->addIncoming($longVal, $coerceEnd);

        return $phi;
    }

    private static function lowerStringOperandFromPtr(
        Context $context,
        Value $strPtr,
        string $function,
        int $userArgIndex,
        string $paramName
    ): Value {
        $isNumeric = self::stringPtrIsNumeric($context, $strPtr);
        $okBlock = BasicBlockHelper::append($context, 'chr_str_ok');
        $errBlock = BasicBlockHelper::append($context, 'chr_str_err');
        $context->builder->branchIf($isNumeric, $okBlock, $errBlock);
        $context->builder->positionAtEnd($errBlock);
        self::emitIntTypeErrorAndAbort($context, $function, $userArgIndex, $paramName, 'string');
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
        $endPtrSlot = $context->builder->alloca($i8p, 1, 'chr_str_end');
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
        $endPtrSlot = $context->builder->alloca($context->getTypeFromString('int8*'), 1, 'chr_strtol_end');
        $context->builder->store($context->getTypeFromString('int8*')->constNull(), $endPtrSlot);
        $raw = $context->builder->call(
            $context->lookupFunction('strtol'),
            $charPtr,
            $endPtrSlot,
            $context->getTypeFromString('int32')->constInt(10, false)
        );

        return $context->builder->trunc($raw, $context->getTypeFromString('int64'));
    }

    private static function compileTimeObjectGivenLabel(Context $context, JITVariable $arg): string
    {
        // Only inspect typed object immediates. Boxed TYPE_VALUE slots are `__value__*`
        // (and NestedJIT may hand a non-pointer); structGep(__object__) aborts emit
        // when VmMetaphone NestedJITs chr()/ord() (#26811 / #26794).
        if (JITVariable::TYPE_OBJECT !== $arg->type || JITVariable::KIND_VALUE !== $arg->kind) {
            return 'object';
        }
        $objMap = $context->structFieldMap['__object__'] ?? null;
        if (null === $objMap || !isset($objMap['class_id'])) {
            return 'object';
        }
        $llvmType = $context->getStringFromType($arg->value->typeOf());
        if ('__object__*' !== $llvmType && !str_ends_with((string) $llvmType, '__object__*')) {
            return 'object';
        }
        $classIdVal = $context->builder->load(
            $context->builder->structGep($arg->value, $objMap['class_id'])
        );
        if (!method_exists($classIdVal, 'isConstant') || !$classIdVal->isConstant()) {
            return 'object';
        }
        $classId = (int) $classIdVal->getConstantValue();

        return $context->type->object->classNameForId($classId);
    }

    private static function compileTimeEnumCaseGivenLabel(Context $context, JITVariable $arg): string
    {
        return self::compileTimeObjectGivenLabel($context, $arg);
    }

    private static function intTypeError(string $function, int $userArgIndex, string $paramName, string $given): string
    {
        return sprintf(
            '%s(): Argument #%d ($%s) must be of type int, %s given',
            $function,
            $userArgIndex,
            $paramName,
            $given
        );
    }

    private static function emitIntTypeErrorAndAbort(
        Context $context,
        string $function,
        int $userArgIndex,
        string $paramName,
        string $given
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, self::intTypeError($function, $userArgIndex, $paramName, $given));
        $context->builder->call($context->lookupFunction('abort'));
    }
}
