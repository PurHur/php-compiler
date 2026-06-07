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
