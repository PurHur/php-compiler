<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\MemoryUsageJit;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Lower memory_get_*() usage parameter (bool legacy + MemoryUsage enum, #7247).
 */
final class JitMemoryUsageArg
{
    public static function lower(Context $context, ?Variable $arg, string $fn): Value
    {
        if (null === $arg) {
            return $context->constantFromBool(false);
        }

        $compileTime = MemoryUsageJit::compileTimeUsageBool($context, $arg);
        if (null !== $compileTime) {
            return $context->constantFromBool($compileTime);
        }

        // Z_PARAM_BOOL — honor caller strict_types; soft-null DEP+coerce (#30346).
        if (Variable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return JitBoolArg::lowerCoerceZParamBool($context, $arg, $fn, 'real_usage', 1);
        }

        if (Variable::TYPE_NATIVE_BOOL === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (Variable::TYPE_NATIVE_LONG === $arg->type) {
            $zero = $context->getTypeFromString('int64')->constInt(0, false);

            return $context->builder->icmp(
                Builder::INT_NE,
                $context->helper->loadValue($arg),
                $zero
            );
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxed($context, $arg, $fn);
        }

        self::emitTypeErrorAndAbort($context, $fn, 'mixed');

        return $context->constantFromBool(false);
    }

    private static function lowerBoxed(Context $context, Variable $arg, string $fn): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');

        $enumBlock = BasicBlockHelper::append($context, 'jit_mem_usage_enum');
        $afterEnum = BasicBlockHelper::append($context, 'jit_mem_usage_after_enum');
        $isEnumCase = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_ENUM_CASE, false)
        );
        $context->builder->branchIf($isEnumCase, $enumBlock, $afterEnum);

        $context->builder->positionAtEnd($enumBlock);
        $enumReal = self::lowerMemoryUsageEnumCase($context, $valuePtr, $fn);
        $enumEnd = $context->builder->getInsertBlock();
        $context->builder->branch($afterEnum);
        $context->builder->positionAtEnd($afterEnum);

        $boolBlock = BasicBlockHelper::append($context, 'jit_mem_usage_bool');
        $longBlock = BasicBlockHelper::append($context, 'jit_mem_usage_long');
        $badBlock = BasicBlockHelper::append($context, 'jit_mem_usage_bad');
        $mergeBlock = BasicBlockHelper::append($context, 'jit_mem_usage_merge');

        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_INTEGER, false)
        );
        $context->builder->branchIf($isBool, $boolBlock, $longBlock);

        $context->builder->positionAtEnd($boolBlock);
        $valueField = $context->builder->structGep($valuePtr, $map['value']);
        $firstByte = $context->builder->inBoundsGEP(
            $valueField,
            $context->getTypeFromString('int32')->constInt(0, false),
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $boolVal = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->load($firstByte),
            $i8->constInt(0, false)
        );
        $boolEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($longBlock);
        $longBody = BasicBlockHelper::append($context, 'jit_mem_usage_long_body');
        $context->builder->branchIf($isLong, $longBody, $badBlock);
        $context->builder->positionAtEnd($longBody);
        $zero = $context->getTypeFromString('int64')->constInt(0, false);
        $longVal = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr),
            $zero
        );
        $longEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($badBlock);
        self::emitTypeErrorAndAbort($context, $fn, self::compileTimeEnumGivenLabel($context, $arg));
        $context->builder->positionAtEnd($mergeBlock);

        $phi = $context->builder->phi($i1);
        $phi->addIncoming($enumReal, $enumEnd);
        $phi->addIncoming($boolVal, $boolEnd);
        $phi->addIncoming($longVal, $longEnd);

        return $phi;
    }

    private static function lowerMemoryUsageEnumCase(Context $context, Value $valuePtr, string $fn): Value
    {
        $classId = self::readEnumClassId($context, $valuePtr);
        $memoryUsageId = $context->type->object->memoryUsageEnumClassId();
        if (null === $memoryUsageId) {
            self::emitTypeErrorAndAbort($context, $fn, 'object');

            return $context->constantFromBool(false);
        }
        $i32 = $context->getTypeFromString('int32');
        $i1 = $context->getTypeFromString('int1');
        $isMemoryUsage = $context->builder->icmp(
            Builder::INT_EQ,
            $classId,
            $i32->constInt($memoryUsageId, false)
        );
        $okBlock = BasicBlockHelper::append($context, 'jit_mem_usage_enum_ok');
        $badBlock = BasicBlockHelper::append($context, 'jit_mem_usage_enum_bad');
        $context->builder->branchIf($isMemoryUsage, $okBlock, $badBlock);
        $context->builder->positionAtEnd($badBlock);
        self::emitTypeErrorAndAbort($context, $fn, 'object');
        $context->builder->positionAtEnd($okBlock);
        $backing = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);

        return $context->builder->icmp(
            Builder::INT_EQ,
            $backing,
            $context->getTypeFromString('int64')->constInt(1, false)
        );
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
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            sprintf('%s(): Argument #1 ($real_usage) must be of type bool, %s given', $fn, $given)
        );
        $context->builder->call($context->lookupFunction('abort'));
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
