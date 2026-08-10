<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitZendScalarCast;
use PHPCompiler\VM\TypeCheck as VmTypeCheck;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Weak typed user-parameter coercion at native AOT call sites (#29745, zend_verify_arg_type).
 *
 * VM/JIT interpret calls; AOT direct native calls must reject non-coercible operands before
 * compileArg would silently cast (e.g. intval("a") → 0).
 */
final class TypedParamCoerce
{
    public static function weakAtCallSite(
        Context $context,
        Variable $arg,
        int $vmConstraint,
        string $functionName,
        int $userParamIndex,
        string $paramName
    ): Variable {
        if (VmVariable::TYPE_INTEGER === $vmConstraint) {
            return self::weakInt($context, $arg, $functionName, $userParamIndex, $paramName);
        }

        $coerced = TypeCheck::coerceParameterWeak($context, $arg, $vmConstraint);
        if (null !== $coerced) {
            return $coerced;
        }

        self::emitTypeErrorAndContinue(
            $context,
            $functionName,
            $userParamIndex,
            $paramName,
            VmTypeCheck::typeNameForConstraint($vmConstraint),
            JitOperandTypeLabel::givenLabel($context, $arg)
        );

        return $arg;
    }

    public static function weakInt(
        Context $context,
        Variable $arg,
        string $functionName,
        int $userParamIndex,
        string $paramName
    ): Variable {
        if (Variable::TYPE_NATIVE_LONG === $arg->type) {
            return $arg;
        }
        if (Variable::TYPE_NATIVE_BOOL === $arg->type) {
            return new Variable(
                $context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $context->builder->zExt(
                    $context->helper->loadValue($arg),
                    $context->getTypeFromString('int64')
                )
            );
        }
        if (Variable::TYPE_NATIVE_DOUBLE === $arg->type) {
            return new Variable(
                $context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                \PHPCompiler\ext\standard\JitIntdiv::floatToLongTypedSafe(
                    $context,
                    $context->helper->loadValue($arg),
                    self::message($context, $functionName, $userParamIndex, $paramName, 'int', 'float')
                )
            );
        }
        if (Variable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            return new Variable(
                $context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $context->getTypeFromString('int64')->constInt(0, false)
            );
        }
        if (Variable::TYPE_STRING === $arg->type) {
            return self::weakIntFromString($context, $arg, $functionName, $userParamIndex, $paramName);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return self::weakIntFromValueBox($context, $arg, $functionName, $userParamIndex, $paramName);
        }

        self::emitTypeErrorAndContinue(
            $context,
            $functionName,
            $userParamIndex,
            $paramName,
            'int',
            JitOperandTypeLabel::givenLabel($context, $arg)
        );

        return $arg;
    }

    private static function weakIntFromString(
        Context $context,
        Variable $arg,
        string $functionName,
        int $userParamIndex,
        string $paramName
    ): Variable {
        $literal = JitStringArg::compileTimeLiteral($arg);
        if (null !== $literal) {
            if ('' === $literal || !is_numeric($literal)) {
                self::emitTypeErrorAndContinue(
                    $context,
                    $functionName,
                    $userParamIndex,
                    $paramName,
                    'int',
                    'string'
                );
            }

            return new Variable(
                $context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $context->constantFromInteger((int) (float) $literal)
            );
        }

        $strPtr = JitStringArg::lower($context, $arg, $functionName.'() argument');
        $isNumeric = self::stringIsNumeric($context, $strPtr);
        $okBlock = BasicBlockHelper::append($context, 'typed_param_int_str_ok');
        $failBlock = BasicBlockHelper::append($context, 'typed_param_int_str_fail');
        $doneBlock = BasicBlockHelper::append($context, 'typed_param_int_str_done');
        $context->builder->branchIf($isNumeric, $okBlock, $failBlock);

        $context->builder->positionAtEnd($failBlock);
        self::emitTypeErrorAndContinue(
            $context,
            $functionName,
            $userParamIndex,
            $paramName,
            'int',
            'string'
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $longVal = JitLongArg::lowerStringValue($context, $strPtr);
        $okEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $i64 = $context->getTypeFromString('int64');
        $phi = $context->builder->phi($i64, 'typed_param_int_str_phi');
        $phi->addIncoming($i64->constInt(0, false), $failBlock);
        $phi->addIncoming($longVal, $okEnd);

        return new Variable($context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $phi);
    }

    private static function weakIntFromValueBox(
        Context $context,
        Variable $arg,
        string $functionName,
        int $userParamIndex,
        string $paramName
    ): Variable {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');

        $nullBlock = BasicBlockHelper::append($context, 'typed_param_int_vbox_null');
        $longBlock = BasicBlockHelper::append($context, 'typed_param_int_vbox_long');
        $boolBlock = BasicBlockHelper::append($context, 'typed_param_int_vbox_bool');
        $doubleBlock = BasicBlockHelper::append($context, 'typed_param_int_vbox_double');
        $stringBlock = BasicBlockHelper::append($context, 'typed_param_int_vbox_string');
        $badBlock = BasicBlockHelper::append($context, 'typed_param_int_vbox_bad');
        $doneBlock = BasicBlockHelper::append($context, 'typed_param_int_vbox_done');

        $afterNull = BasicBlockHelper::append($context, 'typed_param_int_vbox_after_null');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_NULL, false)),
            $nullBlock,
            $afterNull
        );
        $context->builder->positionAtEnd($nullBlock);
        $nullLong = $i64->constInt(0, false);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterNull);
        $afterLong = BasicBlockHelper::append($context, 'typed_param_int_vbox_after_long');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_NATIVE_LONG, false)),
            $longBlock,
            $afterLong
        );
        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $longEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterLong);
        $afterBool = BasicBlockHelper::append($context, 'typed_param_int_vbox_after_bool');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)),
            $boolBlock,
            $afterBool
        );
        $context->builder->positionAtEnd($boolBlock);
        $boolLong = JitZendScalarCast::readBoolByteFromValueBox($context, $valuePtr, $i64);
        $boolEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterBool);
        $afterDouble = BasicBlockHelper::append($context, 'typed_param_int_vbox_after_double');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)),
            $doubleBlock,
            $afterDouble
        );
        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $valuePtr);
        $doubleLong = \PHPCompiler\ext\standard\JitIntdiv::floatToLongWithPrecisionWarning(
            $context,
            $doubleVal
        );
        $doubleEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterDouble);
        $afterString = BasicBlockHelper::append($context, 'typed_param_int_vbox_after_string');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_STRING, false)),
            $stringBlock,
            $afterString
        );

        $context->builder->positionAtEnd($stringBlock);
        $strPtr = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $isNumeric = self::stringIsNumeric($context, $strPtr);
        $strOkBlock = BasicBlockHelper::append($context, 'typed_param_int_vbox_str_ok');
        $strFailBlock = BasicBlockHelper::append($context, 'typed_param_int_vbox_str_fail');
        $context->builder->branchIf($isNumeric, $strOkBlock, $strFailBlock);
        $context->builder->positionAtEnd($strFailBlock);
        self::emitTypeErrorAndContinue(
            $context,
            $functionName,
            $userParamIndex,
            $paramName,
            'int',
            'string'
        );
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($strOkBlock);
        $stringLong = JitLongArg::lowerStringValue($context, $strPtr);
        $stringEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterString);
        $context->builder->branch($badBlock);
        $context->builder->positionAtEnd($badBlock);
        self::emitTypeErrorAndContinue(
            $context,
            $functionName,
            $userParamIndex,
            $paramName,
            'int',
            JitOperandTypeLabel::givenLabel($context, $arg)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i64, 'typed_param_int_vbox_phi');
        $phi->addIncoming($nullLong, $nullBlock);
        $phi->addIncoming($longVal, $longEnd);
        $phi->addIncoming($boolLong, $boolEnd);
        $phi->addIncoming($doubleLong, $doubleEnd);
        $phi->addIncoming($i64->constInt(0, false), $strFailBlock);
        $phi->addIncoming($stringLong, $stringEnd);
        $phi->addIncoming($i64->constInt(0, false), $badBlock);

        return new Variable($context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $phi);
    }

    /** Zend is_numeric()-shaped check for weak int param/return coercion (#29745 / #29858). */
    public static function stringIsNumeric(Context $context, Value $strPtr): Value
    {
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $zero = $len->typeOf()->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);

        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtrSlot = $context->builder->alloca(
            $context->getTypeFromString('int8*'),
            1,
            'typed_param_is_numeric_end'
        );
        $nullEnd = $context->getTypeFromString('int8*')->constNull();
        $context->builder->store($nullEnd, $endPtrSlot);
        $context->builder->call($context->lookupFunction('strtod'), $charPtr, $endPtrSlot);
        $endPtr = $context->builder->load($endPtrSlot);
        $notConsumed = $context->builder->icmp(Builder::INT_EQ, $endPtr, $charPtr);
        $i64 = $context->getTypeFromString('int64');
        $endOffset = $context->builder->sub(
            $context->builder->ptrToInt($endPtr, $i64),
            $context->builder->ptrToInt($charPtr, $i64)
        );
        $consumedAll = $context->builder->icmp(Builder::INT_EQ, $endOffset, $len);
        $numeric = $context->builder->and(
            $context->builder->not($notConsumed),
            $consumedAll
        );

        return $context->builder->select($isEmpty, $context->constantFromBool(false), $numeric);
    }

    private static function emitTypeErrorAndContinue(
        Context $context,
        string $functionName,
        int $userParamIndex,
        string $paramName,
        string $expected,
        string $given
    ): void {
        ExceptionBridge::emitTypeErrorAndAbort(
            $context,
            self::message($context, $functionName, $userParamIndex, $paramName, $expected, $given)
        );
        BasicBlockHelper::ensureOpenInsertBlock($context, 'typed_param_int_te_cont');
    }

    private static function message(
        Context $context,
        string $functionName,
        int $userParamIndex,
        string $paramName,
        string $expected,
        string $given
    ): string {
        $message = sprintf(
            '%s(): Argument #%d ($%s) must be of type %s, %s given',
            $functionName,
            $userParamIndex + 1,
            $paramName,
            $expected,
            $given
        );
        $path = $context->jitAotEntryScriptPath;
        if ($context->callSiteLine > 0 && '' !== $path) {
            $message .= sprintf(', called in %s on line %d', $path, $context->callSiteLine);
        }

        return $message;
    }
}
