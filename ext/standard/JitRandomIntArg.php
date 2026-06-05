<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for random_int() bound operands — Z_PARAM_LONG enum rejection (#5795). */
final class JitRandomIntArg
{
    public static function lowerBound(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName
    ): Value {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            self::emitIntTypeErrorAndAbort(
                $context,
                $argIndex,
                $paramName,
                self::compileTimeObjectGivenLabel($context, $arg)
            );

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxedOperand($context, $arg, $argIndex, $paramName);
        }

        return JitLongArg::lower(
            $context,
            $arg,
            sprintf('random_int() argument #%d', $argIndex)
        );
    }

    private static function lowerBoxedOperand(
        Context $context,
        JITVariable $arg,
        int $argIndex,
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

        $nullBlock = BasicBlockHelper::append($context, 'random_int_box_null');
        $afterNull = BasicBlockHelper::append($context, 'random_int_box_after_null');
        $arrayBlock = BasicBlockHelper::append($context, 'random_int_box_array');
        $objectBlock = BasicBlockHelper::append($context, 'random_int_box_object');
        $coerceBlock = BasicBlockHelper::append($context, 'random_int_box_coerce');
        $mergeBlock = BasicBlockHelper::append($context, 'random_int_box_merge');
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
        self::emitIntTypeErrorAndAbort($context, $argIndex, $paramName, 'array');

        $context->builder->positionAtEnd($objectBlock);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeByte, $objectTy);
        $errBlock = BasicBlockHelper::append($context, 'random_int_box_object_err');
        $afterObject = BasicBlockHelper::append($context, 'random_int_box_after_object');
        $context->builder->branchIf($isObject, $errBlock, $afterObject);

        $context->builder->positionAtEnd($errBlock);
        self::emitIntTypeErrorAndAbort($context, $argIndex, $paramName, 'object');

        $context->builder->positionAtEnd($afterObject);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeByte, $enumCaseTy);
        $enumErrBlock = BasicBlockHelper::append($context, 'random_int_box_enum_err');
        $afterEnum = BasicBlockHelper::append($context, 'random_int_box_after_enum');
        $context->builder->branchIf($isEnumCase, $enumErrBlock, $afterEnum);

        $context->builder->positionAtEnd($enumErrBlock);
        self::emitIntTypeErrorAndAbort(
            $context,
            $argIndex,
            $paramName,
            self::compileTimeEnumCaseGivenLabel($context, $arg)
        );

        $context->builder->positionAtEnd($afterEnum);
        $context->builder->branch($coerceBlock);

        $context->builder->positionAtEnd($coerceBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $coerceEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($i64, 'random_int_box_phi');
        $phi->addIncoming($zero, $nullBlock);
        $phi->addIncoming($longVal, $coerceEnd);

        return $phi;
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

    private static function intTypeError(int $argIndex, string $paramName, string $given): string
    {
        return sprintf(
            'random_int(): Argument #%d ($%s) must be of type int, %s given',
            $argIndex,
            $paramName,
            $given
        );
    }

    private static function emitIntTypeErrorAndAbort(
        Context $context,
        int $argIndex,
        string $paramName,
        string $given
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, self::intTypeError($argIndex, $paramName, $given));
        $context->builder->call($context->lookupFunction('abort'));
    }
}
