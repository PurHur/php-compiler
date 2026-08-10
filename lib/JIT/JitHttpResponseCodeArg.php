<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\VmHttpResponse;
use PHPCompiler\JIT\Builtin\HttpResponseCodeJit;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Lower http_response_code() response_code parameter (int legacy + ResponseCode enum, #7322).
 */
final class JitHttpResponseCodeArg
{
    public static function lower(Context $context, Variable $arg, string $fn): Value
    {
        $compileTime = HttpResponseCodeJit::compileTimeCodeLong($context, $arg);
        if (null !== $compileTime) {
            return $context->getTypeFromString('int64')->constInt($compileTime, false);
        }

        if (Variable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            // Z_PARAM_LONG: declare(strict_types=1) → TypeError; else soft-null DEP+coerce (#30019).
            if ($context->callerStrictTypes) {
                self::emitTypeErrorAndAbort($context, $fn, 'null');

                return $context->getTypeFromString('int64')->constInt(0, false);
            }
            \PHPCompiler\ext\standard\JitIntdiv::emitNullIntDeprecation(
                $context,
                'http_response_code',
                1,
                'response_code'
            );

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxed($context, $arg, $fn);
        }
        if (
            Variable::TYPE_NATIVE_LONG === $arg->type
            || Variable::TYPE_STRING === $arg->type
            || Variable::TYPE_NATIVE_BOOL === $arg->type
        ) {
            return JitLongArg::lower($context, $arg, 'http_response_code(): Argument #1 ($response_code)');
        }

        // Non-lowerable static types — Zend Z_PARAM_LONG TypeError (#6037), not LogicException.
        self::emitTypeErrorAndAbort($context, $fn, self::compileTimeEnumGivenLabel($context, $arg));

        return $context->getTypeFromString('int64')->constInt(0, false);
    }

    private static function lowerBoxed(Context $context, Variable $arg, string $fn): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);

        $nullBlock = BasicBlockHelper::append($context, 'jit_hrc_null');
        $afterNull = BasicBlockHelper::append($context, 'jit_hrc_after_null');
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_NULL, false)
        );
        $context->builder->branchIf($isNull, $nullBlock, $afterNull);

        $context->builder->positionAtEnd($nullBlock);
        // Z_PARAM_LONG boxed null: strict → TypeError; else soft-null DEP+0 (#30019).
        if ($context->callerStrictTypes) {
            self::emitTypeErrorAndAbort($context, $fn, 'null');
        } else {
            \PHPCompiler\ext\standard\JitIntdiv::emitNullIntDeprecation(
                $context,
                'http_response_code',
                1,
                'response_code'
            );
        }
        $nullEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock = BasicBlockHelper::append($context, 'jit_hrc_merge'));

        $context->builder->positionAtEnd($afterNull);
        $enumBlock = BasicBlockHelper::append($context, 'jit_hrc_enum');
        $afterEnum = BasicBlockHelper::append($context, 'jit_hrc_after_enum');
        $isEnumCase = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_ENUM_CASE, false)
        );
        $context->builder->branchIf($isEnumCase, $enumBlock, $afterEnum);

        $context->builder->positionAtEnd($enumBlock);
        $enumLong = self::lowerResponseCodeEnumCase($context, $valuePtr, $fn);
        $enumEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($afterEnum);
        $stringTy = $i8->constInt(VmVariable::TYPE_STRING, false);
        $boolTy = $i8->constInt(VmVariable::TYPE_BOOLEAN, false);
        $longTy = $i8->constInt(VmVariable::TYPE_INTEGER, false);
        $arrayTy = $i8->constInt(VmVariable::TYPE_ARRAY, false);

        $stringBlock = BasicBlockHelper::append($context, 'jit_hrc_string');
        $scalarBlock = BasicBlockHelper::append($context, 'jit_hrc_scalar');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $stringTy),
            $stringBlock,
            $scalarBlock
        );

        $context->builder->positionAtEnd($stringBlock);
        $strPtr = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $stringLong = JitLongArg::lowerStringValue($context, $strPtr);
        $stringEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($scalarBlock);
        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeByte, $arrayTy);
        $arrayBlock = BasicBlockHelper::append($context, 'jit_hrc_array');
        $afterArray = BasicBlockHelper::append($context, 'jit_hrc_after_array');
        $context->builder->branchIf($isArray, $arrayBlock, $afterArray);

        $context->builder->positionAtEnd($arrayBlock);
        self::emitTypeErrorAndAbort($context, $fn, 'array');

        $context->builder->positionAtEnd($afterArray);
        $isLongOrBool = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $longTy),
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $boolTy)
        );
        $longBlock = BasicBlockHelper::append($context, 'jit_hrc_long');
        $badBlock = BasicBlockHelper::append($context, 'jit_hrc_bad');
        $context->builder->branchIf($isLongOrBool, $longBlock, $badBlock);

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $longEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($badBlock);
        self::emitTypeErrorAndAbort($context, $fn, self::compileTimeEnumGivenLabel($context, $arg));
        $context->builder->positionAtEnd($mergeBlock);

        $phi = $context->builder->phi($i64);
        $phi->addIncoming($zero, $nullEnd);
        $phi->addIncoming($enumLong, $enumEnd);
        $phi->addIncoming($stringLong, $stringEnd);
        $phi->addIncoming($longVal, $longEnd);

        return $phi;
    }

    private static function lowerResponseCodeEnumCase(Context $context, Value $valuePtr, string $fn): Value
    {
        $classId = self::readEnumClassId($context, $valuePtr);
        $responseCodeId = $context->type->object->responseCodeEnumClassId();
        if (null === $responseCodeId) {
            self::emitTypeErrorAndAbort($context, $fn, 'object');

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        $i32 = $context->getTypeFromString('int32');
        $isResponseCode = $context->builder->icmp(
            Builder::INT_EQ,
            $classId,
            $i32->constInt($responseCodeId, false)
        );
        $okBlock = BasicBlockHelper::append($context, 'jit_hrc_enum_ok');
        $badBlock = BasicBlockHelper::append($context, 'jit_hrc_enum_bad');
        $context->builder->branchIf($isResponseCode, $okBlock, $badBlock);
        $context->builder->positionAtEnd($badBlock);
        self::emitTypeErrorAndAbort($context, $fn, 'object');
        $context->builder->positionAtEnd($okBlock);

        return $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
    }

    private static function readEnumClassId(Context $context, Value $valuePtr): Value
    {
        $enumMap = $context->structFieldMap['__enum_case__'] ?? null;
        if (null !== $enumMap && isset($enumMap['class_id'])) {
            return $context->builder->load(
                $context->builder->structGep($valuePtr, $enumMap['class_id'])
            );
        }

        return $context->getTypeFromString('int32')->constInt(0, false);
    }

    private static function emitTypeErrorAndAbort(Context $context, string $fn, string $given): void
    {
        ExceptionBridge::emitTypeErrorAndAbort(
            $context,
            sprintf(
                'http_response_code(): Argument #1 ($response_code) must be of type int, %s given',
                $given
            )
        );
    }

    private static function compileTimeEnumGivenLabel(Context $context, Variable $arg): string
    {
        if (Variable::KIND_VALUE !== $arg->kind) {
            return 'object';
        }
        $enumMap = $context->structFieldMap['__enum_case__'] ?? null;
        if (null !== $enumMap && isset($enumMap['class_id'])) {
            $classIdVal = $context->builder->load(
                $context->builder->structGep($arg->value, $enumMap['class_id'])
            );
            if (method_exists($classIdVal, 'isConstant') && $classIdVal->isConstant()) {
                $classId = (int) $classIdVal->getConstantValue();

                return $context->type->object->classNameForId($classId);
            }
        }

        return 'object';
    }
}
