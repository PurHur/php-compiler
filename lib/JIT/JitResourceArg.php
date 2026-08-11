<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Resource builtin operand guards — reject enum case before backing coercion (#5845, php-src-strict).
 *
 * php-src: ext/standard/basic_functions.c — get_resource_type, get_resource_id, is_resource
 */
final class JitResourceArg
{
    public static function resourceTypeErrorMessage(
        string $function,
        int $argIndex,
        string $paramName,
        string $given
    ): string {
        return \sprintf(
            '%s(): Argument #%d ($%s) must be of type resource, %s given',
            $function,
            $argIndex + 1,
            $paramName,
            $given
        );
    }

    public static function emitResourceTypeErrorAndAbort(
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
            self::resourceTypeErrorMessage($function, $argIndex, $paramName, $given)
        );
        $context->builder->call($context->lookupFunction('abort'));
    }

    /**
     * Resource TypeError actual from operand — true/false not bool (#30118).
     */
    public static function emitResourceTypeErrorForOperandAndAbort(
        Context $context,
        string $function,
        int $argIndex,
        string $paramName,
        Variable $arg
    ): void {
        if (Variable::TYPE_NATIVE_BOOL === $arg->type) {
            $boolVal = $arg->value;
            $isTrue = $context->builder->icmp(
                Builder::INT_NE,
                $boolVal,
                $boolVal->typeOf()->constInt(0, false)
            );
            $trueBb = BasicBlockHelper::append($context, 'resource_native_true');
            $falseBb = BasicBlockHelper::append($context, 'resource_native_false');
            $context->builder->branchIf($isTrue, $trueBb, $falseBb);
            $context->builder->positionAtEnd($trueBb);
            self::emitResourceTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'true');
            $context->builder->positionAtEnd($falseBb);
            self::emitResourceTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'false');

            return;
        }
        if (Variable::TYPE_VALUE === $arg->type || JitValueBox::isValueOperand($arg)) {
            self::emitResourceTypeErrorFromValueBoxAndAbort(
                $context,
                $function,
                $argIndex,
                $paramName,
                JitValueBox::valuePtrFromVariable($context, $arg)
            );

            return;
        }
        self::emitResourceTypeErrorAndAbort(
            $context,
            $function,
            $argIndex,
            $paramName,
            JitOperandTypeLabel::givenLabel($context, $arg)
        );
    }

    /**
     * Runtime TypeError actual from boxed `__value__` (true/false not bool) (#30118).
     */
    public static function emitResourceTypeErrorFromValueBoxAndAbort(
        Context $context,
        string $function,
        int $argIndex,
        string $paramName,
        Value $valuePtr
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));

        $nullBb = BasicBlockHelper::append($context, 'resource_te_null');
        $afterNull = BasicBlockHelper::append($context, 'resource_te_after_null');
        $intBb = BasicBlockHelper::append($context, 'resource_te_int');
        $afterInt = BasicBlockHelper::append($context, 'resource_te_after_int');
        $floatBb = BasicBlockHelper::append($context, 'resource_te_float');
        $afterFloat = BasicBlockHelper::append($context, 'resource_te_after_float');
        $boolBb = BasicBlockHelper::append($context, 'resource_te_bool');
        $afterBool = BasicBlockHelper::append($context, 'resource_te_after_bool');
        $stringBb = BasicBlockHelper::append($context, 'resource_te_string');
        $mixedBb = BasicBlockHelper::append($context, 'resource_te_mixed');

        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(VmVariable::TYPE_NULL & 0x7f, false)
        );
        $context->builder->branchIf($isNull, $nullBb, $afterNull);
        $context->builder->positionAtEnd($nullBb);
        self::emitResourceTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'null');

        $context->builder->positionAtEnd($afterNull);
        $isInt = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(VmVariable::TYPE_INTEGER & 0x7f, false)
        );
        $context->builder->branchIf($isInt, $intBb, $afterInt);
        $context->builder->positionAtEnd($intBb);
        self::emitResourceTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'int');

        $context->builder->positionAtEnd($afterInt);
        $isFloat = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(VmVariable::TYPE_FLOAT & 0x7f, false)
        );
        $context->builder->branchIf($isFloat, $floatBb, $afterFloat);
        $context->builder->positionAtEnd($floatBb);
        self::emitResourceTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'float');

        $context->builder->positionAtEnd($afterFloat);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(VmVariable::TYPE_BOOLEAN & 0x7f, false)
        );
        $context->builder->branchIf($isBool, $boolBb, $afterBool);
        $context->builder->positionAtEnd($boolBb);
        $boolByte = JitValueBox::readBoolByte($context, $valuePtr);
        $isTrue = $context->builder->icmp(Builder::INT_NE, $boolByte, $i8->constInt(0, false));
        $trueBb = BasicBlockHelper::append($context, 'resource_te_true');
        $falseBb = BasicBlockHelper::append($context, 'resource_te_false');
        $context->builder->branchIf($isTrue, $trueBb, $falseBb);
        $context->builder->positionAtEnd($trueBb);
        self::emitResourceTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'true');
        $context->builder->positionAtEnd($falseBb);
        self::emitResourceTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'false');

        $context->builder->positionAtEnd($afterBool);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(VmVariable::TYPE_STRING & 0x7f, false)
        );
        $context->builder->branchIf($isString, $stringBb, $mixedBb);
        $context->builder->positionAtEnd($stringBb);
        self::emitResourceTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'string');

        $context->builder->positionAtEnd($mixedBb);
        self::emitResourceTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'mixed');
    }

    /**
     * Reject enum case operands before JitLongArg reads backing scalars (#5845).
     */
    public static function rejectEnumCaseOperand(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex = 0,
        string $paramName = 'resource'
    ): void {
        $enumLabel = JitOperandTypeLabel::compileTimeEnumClassName($context, $arg);
        if (null !== $enumLabel) {
            self::emitResourceTypeErrorAndAbort($context, $function, $argIndex, $paramName, $enumLabel);

            return;
        }
        if (Variable::TYPE_VALUE !== $arg->type) {
            return;
        }
        self::emitRuntimeValueBoxEnumGuard($context, $arg, $function, $argIndex, $paramName);
    }

    /**
     * Lower is_resource() for boxed operands — enum cases return false without backing coercion.
     */
    public static function lowerIsResource(Context $context, Variable $arg): Value
    {
        if (Variable::TYPE_NULL === $arg->type) {
            return $context->constantFromBool(false);
        }
        if (null !== JitOperandTypeLabel::compileTimeEnumClassName($context, $arg)) {
            return $context->constantFromBool(false);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return self::lowerIsResourceValueBox($context, $arg);
        }

        return \PHPCompiler\ext\standard\JitIsResource::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $arg, 'is_resource() argument #1'),
                $context->getTypeFromString('int64')
            )
        );
    }

    private static function lowerIsResourceValueBox(Context $context, Variable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $enumCaseTy = $i8->constInt(VmVariable::TYPE_ENUM_CASE, false);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeByte, $enumCaseTy);

        $enumBlock = BasicBlockHelper::append($context, 'is_resource_enum_false');
        $handleBlock = BasicBlockHelper::append($context, 'is_resource_handle');
        $doneBlock = BasicBlockHelper::append($context, 'is_resource_done');
        $context->builder->branchIf($isEnumCase, $enumBlock, $handleBlock);

        $context->builder->positionAtEnd($enumBlock);
        $enumEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($handleBlock);
        $handle = $context->builder->truncOrBitCast(
            $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $valuePtr
            ),
            $context->getTypeFromString('int64')
        );
        $isRes = \PHPCompiler\ext\standard\JitIsResource::invoke($context, $handle);
        $handleEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $i1 = $context->getTypeFromString('int1');
        $phi = $context->builder->phi($i1, 'is_resource_phi');
        $phi->addIncoming($context->constantFromBool(false), $enumEnd);
        $phi->addIncoming($isRes, $handleEnd);

        return $phi;
    }

    private static function emitRuntimeValueBoxEnumGuard(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): void {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $enumCaseTy = $i8->constInt(VmVariable::TYPE_ENUM_CASE, false);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeByte, $enumCaseTy);

        $okBlock = BasicBlockHelper::append($context, 'resource_arg_ok');
        $enumBlock = BasicBlockHelper::append($context, 'resource_arg_enum');
        $context->builder->branchIf($isEnumCase, $enumBlock, $okBlock);

        $context->builder->positionAtEnd($enumBlock);
        self::emitResourceTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'object');

        $context->builder->positionAtEnd($okBlock);
    }
}
