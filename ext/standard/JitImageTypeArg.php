<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM arg lowering for image_type builtins (ext/standard/image.c; #6091, #14851). */
final class JitImageTypeArg
{
    public static function lowerImageType(
        Context $context,
        JITVariable $arg,
        string $builtinName,
        string $paramName = 'image_type'
    ): Value {
        if (JITVariable::TYPE_NULL === $arg->type) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (($arg->type & JITVariable::IS_NATIVE_ARRAY) || JITVariable::TYPE_HASHTABLE === $arg->type) {
            self::emitIntTypeErrorAndAbort($context, 'array', $builtinName, $paramName);

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            self::emitIntTypeErrorAndAbort(
                $context,
                self::compileTimeObjectGivenLabel($context, $arg),
                $builtinName,
                $paramName
            );

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE === $arg->type) {
            return $context->builder->fptosi(
                $context->helper->loadValue($arg),
                $context->getTypeFromString('int64')
            );
        }
        if (JITVariable::TYPE_STRING === $arg->type) {
            return self::lowerStringOperand($context, $arg, $builtinName, $paramName);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxedOperand($context, $arg, $builtinName, $paramName);
        }

        return JitLongArg::lower($context, $arg, $builtinName . '() ' . $paramName);
    }

    private static function lowerStringOperand(
        Context $context,
        JITVariable $arg,
        string $builtinName,
        string $paramName
    ): Value {
        $strPtr = JitStringArg::lower($context, $arg, $builtinName . '() ' . $paramName);
        $isNumeric = self::stringPtrIsNumeric($context, $strPtr);
        $okBlock = BasicBlockHelper::append($context, 'imgext_str_ok');
        $errBlock = BasicBlockHelper::append($context, 'imgext_str_err');
        $context->builder->branchIf($isNumeric, $okBlock, $errBlock);
        $context->builder->positionAtEnd($errBlock);
        self::emitIntTypeErrorAndAbort($context, 'string', $builtinName, $paramName);
        $context->builder->positionAtEnd($okBlock);

        return self::stringPtrToLong($context, $strPtr);
    }

    private static function lowerBoxedOperand(
        Context $context,
        JITVariable $arg,
        string $builtinName,
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

        $nullBlock = BasicBlockHelper::append($context, 'imgext_box_null');
        $afterNull = BasicBlockHelper::append($context, 'imgext_box_after_null');
        $arrayBlock = BasicBlockHelper::append($context, 'imgext_box_array');
        $objectBlock = BasicBlockHelper::append($context, 'imgext_box_object');
        $doubleBlock = BasicBlockHelper::append($context, 'imgext_box_double');
        $stringBlock = BasicBlockHelper::append($context, 'imgext_box_string');
        $coerceBlock = BasicBlockHelper::append($context, 'imgext_box_coerce');
        $mergeBlock = BasicBlockHelper::append($context, 'imgext_box_merge');
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
        self::emitIntTypeErrorAndAbort($context, 'array', $builtinName, $paramName);

        $context->builder->positionAtEnd($objectBlock);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeByte, $objectTy);
        $errBlock = BasicBlockHelper::append($context, 'imgext_box_object_err');
        $afterObject = BasicBlockHelper::append($context, 'imgext_box_after_object');
        $context->builder->branchIf($isObject, $errBlock, $afterObject);

        $context->builder->positionAtEnd($errBlock);
        self::emitIntTypeErrorAndAbort($context, 'object', $builtinName, $paramName);

        $context->builder->positionAtEnd($afterObject);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeByte, $enumCaseTy);
        $enumErrBlock = BasicBlockHelper::append($context, 'imgext_box_enum_err');
        $afterEnum = BasicBlockHelper::append($context, 'imgext_box_after_enum');
        $context->builder->branchIf($isEnumCase, $enumErrBlock, $afterEnum);

        $context->builder->positionAtEnd($enumErrBlock);
        self::emitIntTypeErrorAndAbort(
            $context,
            self::compileTimeEnumCaseGivenLabel($context, $arg),
            $builtinName,
            $paramName
        );

        $context->builder->positionAtEnd($afterEnum);
        $isDouble = $context->builder->icmp(Builder::INT_EQ, $typeByte, $doubleTy);
        $context->builder->branchIf($isDouble, $doubleBlock, $stringBlock);

        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $valuePtr);
        $truncated = $context->builder->fptosi($doubleVal, $i64);
        $doubleEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($stringBlock);
        $isString = $context->builder->icmp(Builder::INT_EQ, $typeByte, $stringTy);
        $stringCoerce = BasicBlockHelper::append($context, 'imgext_box_string_coerce');
        $context->builder->branchIf($isString, $stringCoerce, $coerceBlock);

        $context->builder->positionAtEnd($stringCoerce);
        $strVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $strLong = self::lowerStringOperandFromPtr($context, $strVal, $builtinName, $paramName);
        $stringEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($coerceBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $coerceEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($i64, 'imgext_box_phi');
        $phi->addIncoming($zero, $nullBlock);
        $phi->addIncoming($truncated, $doubleEnd);
        $phi->addIncoming($strLong, $stringEnd);
        $phi->addIncoming($longVal, $coerceEnd);

        return $phi;
    }

    private static function lowerStringOperandFromPtr(
        Context $context,
        Value $strPtr,
        string $builtinName,
        string $paramName
    ): Value {
        $isNumeric = self::stringPtrIsNumeric($context, $strPtr);
        $okBlock = BasicBlockHelper::append($context, 'imgext_box_str_ok');
        $errBlock = BasicBlockHelper::append($context, 'imgext_box_str_err');
        $context->builder->branchIf($isNumeric, $okBlock, $errBlock);
        $context->builder->positionAtEnd($errBlock);
        self::emitIntTypeErrorAndAbort($context, 'string', $builtinName, $paramName);
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
        $endPtrSlot = $context->builder->alloca($i8p, 1, 'imgext_str_end');
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

    private static function stringPtrToLong(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtrSlot = $context->builder->alloca($context->getTypeFromString('int8*'), 1, 'imgext_strtol_end');
        $context->builder->store($context->getTypeFromString('int8*')->constNull(), $endPtrSlot);
        $raw = // strtol(3) via LibcExtern::ensureStrtolDecl after always-on drop (#31988).
        LibcExtern::ensureStrtolDecl($context);
        $context->builder->call(
            $context->lookupFunction('strtol'),
            $charPtr,
            $endPtrSlot,
            $context->getTypeFromString('int32')->constInt(10, false)
        );

        return $context->builder->trunc($raw, $context->getTypeFromString('int64'));
    }

    private static function compileTimeObjectGivenLabel(Context $context, JITVariable $arg): string
    {
        if (JITVariable::KIND_VALUE !== $arg->kind) {
            return 'object';
        }
        $objMap = $context->structFieldMap['__object__'] ?? null;
        if (null === $objMap || !isset($objMap['class_id'])) {
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

    private static function intTypeError(string $given, string $builtinName, string $paramName): string
    {
        return sprintf(
            '%s(): Argument #1 ($%s) must be of type int, %s given',
            $builtinName,
            $paramName,
            $given
        );
    }

    private static function emitIntTypeErrorAndAbort(
        Context $context,
        string $given,
        string $builtinName,
        string $paramName = 'image_type'
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, self::intTypeError($given, $builtinName, $paramName));
        $context->builder->call($context->lookupFunction('abort'));
    }
}
