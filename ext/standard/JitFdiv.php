<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for fdiv() operand coercion (php-src math.c; #4388, #6185 enum-case TypeError). */
final class JitFdiv
{
    private const FUNCTION = 'fdiv';

    public static function lowerOperands(
        Context $context,
        JITVariable $num1,
        JITVariable $num2,
        string $function = self::FUNCTION,
        string $param1 = 'num1',
        string $param2 = 'num2',
        string $expectedType = 'float',
        bool $forwardProfileStrictDoubleNull = false
    ): array {
        $double = $context->getTypeFromString('double');

        return [
            self::lowerOperand($context, $num1, 1, $param1, $double, $function, $expectedType, $forwardProfileStrictDoubleNull),
            self::lowerOperand($context, $num2, 2, $param2, $double, $function, $expectedType, $forwardProfileStrictDoubleNull),
        ];
    }

    public static function lowerSingleOperand(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName,
        string $function,
        string $expectedType = 'float',
        bool $forwardProfileStrictDoubleNull = false
    ): Value {
        $double = $context->getTypeFromString('double');

        return self::lowerOperand(
            $context,
            $arg,
            $argIndex,
            $paramName,
            $double,
            $function,
            $expectedType,
            $forwardProfileStrictDoubleNull
        );
    }

    private static function lowerOperand(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName,
        $double,
        string $function = self::FUNCTION,
        string $expectedType = 'float',
        bool $forwardProfileStrictDoubleNull = false
    ): Value {
        if (JITVariable::TYPE_NULL === $arg->type) {
            if ($context->callerStrictTypes) {
                if ('number' === $expectedType || 'int|float' === $expectedType) {
                    JitInternalStrictArg::requireNumber($context, $arg, $function, $paramName, $argIndex);
                } elseif ('float' === $expectedType) {
                    JitInternalStrictArg::requireFloat($context, $arg, $function, $paramName, $argIndex);
                }
                // Catchable TypeError terminates the insert block — open a dead cont so
                // callers (MathSin::invoke, …) can emit without "terminator in middle" (#29782).
                BasicBlockHelper::ensureOpenInsertBlock($context, $function.'_null_strict_te_cont');
            } elseif ('number' === $expectedType) {
                self::emitNullNumberDeprecation($context, $function, $argIndex, $paramName, 'int|float');
            } elseif (self::isFloatLikeExpectedType($expectedType) && VmMath::requiresForwardProfileStrictDoubleNull() && $forwardProfileStrictDoubleNull) {
                self::emitNumericTypeErrorAndAbort($context, $argIndex, $paramName, 'null', $function, $expectedType);
                BasicBlockHelper::ensureOpenInsertBlock($context, $function.'_null_fwd_te_cont');
            } elseif (self::isFloatLikeExpectedType($expectedType)) {
                // Z_PARAM_DOUBLE null coerce (sqrt/sin; #19756, #20432). DEP cites "float"
                // even when TypeError label is int|float (number_format; #29976).
                self::emitNullNumberDeprecation($context, $function, $argIndex, $paramName, 'float');
            }

            return $double->constReal(0.0);
        }
        if ($context->callerStrictTypes && ('number' === $expectedType || 'int|float' === $expectedType)) {
            // Z_PARAM_NUMBER / stub int|float: reject string/bool under strict_types (#4189, #29976).
            JitInternalStrictArg::requireNumber($context, $arg, $function, $paramName, $argIndex);
        }
        if ($context->callerStrictTypes && 'float' === $expectedType) {
            JitInternalStrictArg::requireFloat($context, $arg, $function, $paramName, $argIndex);
        }
        if (($arg->type & JITVariable::IS_NATIVE_ARRAY) || JITVariable::TYPE_HASHTABLE === $arg->type) {
            self::emitNumericTypeErrorAndAbort($context, $argIndex, $paramName, 'array', $function, $expectedType);

            return $double->constReal(0.0);
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            self::emitNumericTypeErrorAndAbort(
                $context,
                $argIndex,
                $paramName,
                self::compileTimeObjectGivenLabel($context, $arg),
                $function,
                $expectedType
            );

            return $double->constReal(0.0);
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_STRING === $arg->type) {
            return self::lowerStringOperand($context, $arg, $argIndex, $paramName, $double, $function, $expectedType);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxedOperand(
                $context,
                $arg,
                $argIndex,
                $paramName,
                $double,
                $function,
                $expectedType,
                $forwardProfileStrictDoubleNull
            );
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $arg->type) {
            return $context->builder->uiToFp($context->helper->loadValue($arg), $double);
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->builder->siToFp(JitLongArg::lower($context, $arg, $function.'() argument'), $double);
        }

        throw new \LogicException($function.'() only supports numeric operands in this compiler build');
    }

    private static function lowerStringOperand(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName,
        $double,
        string $function = self::FUNCTION,
        string $expectedType = 'float'
    ): Value {
        $strPtr = JitStringArg::lower($context, $arg, sprintf('%s() argument #%d', $function, $argIndex));

        return self::lowerStringOperandFromPtr($context, $strPtr, $argIndex, $paramName, $double, $function, $expectedType);
    }

    private static function lowerStringOperandFromPtr(
        Context $context,
        Value $strPtr,
        int $argIndex,
        string $paramName,
        $double,
        string $function = self::FUNCTION,
        string $expectedType = 'float'
    ): Value {
        $isNumeric = self::stringPtrIsNumeric($context, $strPtr);
        $okBlock = BasicBlockHelper::append($context, 'fdiv_str_ok');
        $errBlock = BasicBlockHelper::append($context, 'fdiv_str_err');
        $context->builder->branchIf($isNumeric, $okBlock, $errBlock);
        $context->builder->positionAtEnd($errBlock);
        self::emitNumericTypeErrorAndAbort($context, $argIndex, $paramName, 'string', $function, $expectedType);
        $context->builder->positionAtEnd($okBlock);

        return self::stringPtrToDouble($context, $strPtr);
    }

    private static function lowerBoxedOperand(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName,
        $double,
        string $function = self::FUNCTION,
        string $expectedType = 'float',
        bool $forwardProfileStrictDoubleNull = false
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
        // Value boxes store JIT tags ({@see __value__writeDouble} → TYPE_NATIVE_DOUBLE=3).
        // VmVariable::TYPE_FLOAT=2 collides with TYPE_NATIVE_BOOL and misses doubles (#20651).
        $doubleTy = $i8->constInt(JITVariable::TYPE_NATIVE_DOUBLE, false);
        $stringTy = $i8->constInt(VmVariable::TYPE_STRING, false);

        $nullBlock = BasicBlockHelper::append($context, 'fdiv_box_null');
        $afterNull = BasicBlockHelper::append($context, 'fdiv_box_after_null');
        $arrayBlock = BasicBlockHelper::append($context, 'fdiv_box_array');
        $enumBlock = BasicBlockHelper::append($context, 'fdiv_box_enum');
        $objectBlock = BasicBlockHelper::append($context, 'fdiv_box_object');
        $doubleBlock = BasicBlockHelper::append($context, 'fdiv_box_double');
        $stringBlock = BasicBlockHelper::append($context, 'fdiv_box_string');
        $coerceBlock = BasicBlockHelper::append($context, 'fdiv_box_coerce');
        $mergeBlock = BasicBlockHelper::append($context, 'fdiv_box_merge');
        $zero = $double->constReal(0.0);

        $isNull = $context->builder->icmp(Builder::INT_EQ, $typeByte, $nullTy);
        $context->builder->branchIf($isNull, $nullBlock, $afterNull);

        $context->builder->positionAtEnd($nullBlock);
        if ($context->callerStrictTypes && ('number' === $expectedType || 'int|float' === $expectedType)) {
            self::emitNumericTypeErrorAndAbort($context, $argIndex, $paramName, 'null', $function, $expectedType);
        } elseif ($context->callerStrictTypes && 'float' === $expectedType) {
            self::emitNumericTypeErrorAndAbort($context, $argIndex, $paramName, 'null', $function, $expectedType);
        } elseif (!$context->callerStrictTypes && 'number' === $expectedType) {
            self::emitNullNumberDeprecation($context, $function, $argIndex, $paramName, 'int|float');
        } elseif (self::isFloatLikeExpectedType($expectedType) && VmMath::requiresForwardProfileStrictDoubleNull() && $forwardProfileStrictDoubleNull) {
            self::emitNumericTypeErrorAndAbort($context, $argIndex, $paramName, 'null', $function, $expectedType);
        } elseif (self::isFloatLikeExpectedType($expectedType)) {
            self::emitNullNumberDeprecation($context, $function, $argIndex, $paramName, 'float');
        }
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($afterNull);
        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeByte, $arrayTy);
        $context->builder->branchIf($isArray, $arrayBlock, $enumBlock);

        $context->builder->positionAtEnd($arrayBlock);
        self::emitNumericTypeErrorAndAbort($context, $argIndex, $paramName, 'array', $function, $expectedType);

        $context->builder->positionAtEnd($enumBlock);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeByte, $enumCaseTy);
        $afterEnum = BasicBlockHelper::append($context, 'fdiv_box_after_enum');
        $context->builder->branchIf($isEnumCase, $objectBlock, $afterEnum);

        $context->builder->positionAtEnd($objectBlock);
        self::emitNumericTypeErrorAndAbort(
            $context,
            $argIndex,
            $paramName,
            self::compileTimeEnumCaseGivenLabel($context, $arg),
            $function,
            $expectedType
        );

        $context->builder->positionAtEnd($afterEnum);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeByte, $objectTy);
        $objectErrBlock = BasicBlockHelper::append($context, 'fdiv_box_object_err');
        $afterObject = BasicBlockHelper::append($context, 'fdiv_box_after_object');
        $context->builder->branchIf($isObject, $objectErrBlock, $afterObject);

        $context->builder->positionAtEnd($objectErrBlock);
        self::emitNumericTypeErrorAndAbort(
            $context,
            $argIndex,
            $paramName,
            self::compileTimeObjectGivenLabel($context, $arg),
            $function,
            $expectedType
        );

        $context->builder->positionAtEnd($afterObject);
        $isDouble = $context->builder->icmp(Builder::INT_EQ, $typeByte, $doubleTy);
        $context->builder->branchIf($isDouble, $doubleBlock, $stringBlock);

        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $valuePtr);
        $doubleEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($stringBlock);
        $isString = $context->builder->icmp(Builder::INT_EQ, $typeByte, $stringTy);
        $stringCoerce = BasicBlockHelper::append($context, 'fdiv_box_string_coerce');
        $context->builder->branchIf($isString, $stringCoerce, $coerceBlock);

        $context->builder->positionAtEnd($stringCoerce);
        $strVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $strDouble = self::lowerStringOperandFromPtr($context, $strVal, $argIndex, $paramName, $double, $function, $expectedType);
        $stringEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($coerceBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $coerced = $context->builder->siToFp($longVal, $double);
        $coerceEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($double, 'fdiv_box_phi');
        $phi->addIncoming($zero, $nullBlock);
        $phi->addIncoming($doubleVal, $doubleEnd);
        $phi->addIncoming($strDouble, $stringEnd);
        $phi->addIncoming($coerced, $coerceEnd);

        return $phi;
    }

    private static function stringPtrIsNumeric(Context $context, Value $strPtr): Value
    {
        $isIntNumeric = self::stringPtrIsIntegerNumeric($context, $strPtr);

        return $context->builder->or(
            $isIntNumeric,
            self::stringPtrIsDoubleNumeric($context, $strPtr)
        );
    }

    private static function stringPtrIsIntegerNumeric(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $i64->constInt(0, false));
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtrSlot = $context->builder->alloca($i8p, 1, 'fdiv_strtol_end');
        $context->builder->store($i8p->constNull(), $endPtrSlot);
        // strtol(3) via LibcExtern::ensureStrtolDecl after always-on drop (#31988).
        LibcExtern::ensureStrtolDecl($context);
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

    private static function stringPtrIsDoubleNumeric(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtrSlot = $context->builder->alloca($i8p, 1, 'fdiv_strtod_end');
        $context->builder->store($i8p->constNull(), $endPtrSlot);
        $context->builder->call(
            $context->lookupFunction('strtod'),
            $charPtr,
            $endPtrSlot
        );
        $endPtr = $context->builder->load($endPtrSlot);
        $endOffset = $context->builder->sub(
            $context->builder->ptrToInt($endPtr, $i64),
            $context->builder->ptrToInt($charPtr, $i64)
        );

        return $context->builder->icmp(Builder::INT_EQ, $endOffset, $len);
    }

    private static function stringPtrToDouble(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtr = $context->getTypeFromString('int8**')->constNull();

        return $context->builder->call($context->lookupFunction('strtod'), $charPtr, $endPtr);
    }

    private static function compileTimeObjectGivenLabel(Context $context, JITVariable $arg): string
    {
        // Only inspect typed object immediates. Boxed TYPE_VALUE slots are `__value__*`
        // (is_nan/is_finite/is_infinite of an fdiv() result); structGep(__object__) aborts
        // emit / SIGSEGV's the compiler host (#27412 / same shape as #26811).
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
        if (!method_exists($classIdVal, 'isConstant')
            || !$classIdVal->isConstant()
            || !method_exists($classIdVal, 'getConstantValue')
        ) {
            return 'object';
        }
        $classId = (int) $classIdVal->getConstantValue();

        return $context->type->object->classNameForId($classId);
    }

    private static function compileTimeEnumCaseGivenLabel(Context $context, JITVariable $arg): string
    {
        return self::compileTimeObjectGivenLabel($context, $arg);
    }

    private static function numericTypeError(
        int $argIndex,
        string $paramName,
        string $given,
        string $function = self::FUNCTION,
        string $expectedType = 'float'
    ): string {
        // 'number' and stub int|float both print the Zend union; plain float stays float (#29976).
        $expected = self::isIntFloatExpectedType($expectedType) ? 'int|float' : 'float';

        return sprintf(
            '%s(): Argument #%d ($%s) must be of type %s, %s given',
            $function,
            $argIndex,
            $paramName,
            $expected,
            $given
        );
    }

    /** Z_PARAM_DOUBLE-style expectedType that still soft-nulls with a "float" DEP. */
    private static function isFloatLikeExpectedType(string $expectedType): bool
    {
        return 'float' === $expectedType || 'int|float' === $expectedType;
    }

    /** TypeError / Z_PARAM_NUMBER union label. */
    private static function isIntFloatExpectedType(string $expectedType): bool
    {
        return 'number' === $expectedType || 'int|float' === $expectedType;
    }

    private static function emitNumericTypeErrorAndAbort(
        Context $context,
        int $argIndex,
        string $paramName,
        string $given,
        string $function = self::FUNCTION,
        string $expectedType = 'float'
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            self::numericTypeError($argIndex, $paramName, $given, $function, $expectedType)
        );
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function emitNullNumberDeprecation(
        Context $context,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType = 'int|float'
    ): void {
        if (NestedJitCompileScope::isActive()) {
            return;
        }
        JitBuiltinWarning::emitDeprecated(
            $context,
            VmNullNumberParamDeprecation::message($function, $argIndex, $paramName, $expectedType)
        );
    }
}
