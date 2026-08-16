<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\filter\VmFilter;
use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\JIT\Builtin\FilterInputTypeJit;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Lower filter_input() / filter_has_var() / filter_input_array() $type parameter
 * (int legacy + PhpInputFilter enum, #7284; soft-null #31486).
 */
final class JitFilterInputTypeArg
{
    public static function lower(Context $context, Variable $arg, string $function = 'filter_input'): Value
    {
        $param = VmFilter::inputTypeParamName($function);
        // php-src Z_PARAM_LONG — soft-null DEP+0; strict TypeError (#31486).
        if (Variable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            if ($context->callerStrictTypes) {
                self::emitTypeErrorAndAbort($context, $function, $param, 'null');
                BasicBlockHelper::ensureOpenInsertBlock($context, 'jit_filter_input_type_null_strict_dead');

                return $context->getTypeFromString('int64')->constInt(0, false);
            }
            JitIntdiv::emitNullIntDeprecation($context, $function, 1, $param, 'int');

            return $context->getTypeFromString('int64')->constInt(0, false);
        }

        $compileTime = FilterInputTypeJit::compileTimeInputType($context, $arg);
        if (null !== $compileTime) {
            return $context->getTypeFromString('int64')->constInt($compileTime, false);
        }

        if (Variable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxed($context, $arg, $function, $param);
        }

        self::emitTypeErrorAndAbort($context, $function, $param, 'mixed');

        return $context->getTypeFromString('int64')->constInt(0, false);
    }

    private static function lowerBoxed(
        Context $context,
        Variable $arg,
        string $function,
        string $param
    ): Value {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');

        $nullBlock = BasicBlockHelper::append($context, 'jit_filter_input_type_null');
        $enumBlock = BasicBlockHelper::append($context, 'jit_filter_input_type_enum');
        $longBlock = BasicBlockHelper::append($context, 'jit_filter_input_type_long');
        $badBlock = BasicBlockHelper::append($context, 'jit_filter_input_type_bad');
        $mergeBlock = BasicBlockHelper::append($context, 'jit_filter_input_type_merge');
        $afterNull = BasicBlockHelper::append($context, 'jit_filter_input_type_after_null');
        $afterEnum = BasicBlockHelper::append($context, 'jit_filter_input_type_after_enum');

        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_NULL, false)
        );
        $context->builder->branchIf($isNull, $nullBlock, $afterNull);

        $context->builder->positionAtEnd($nullBlock);
        $nullResult = self::lowerRuntimeNull($context, $function, $param);
        $nullEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($afterNull);
        $isEnumCase = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_ENUM_CASE, false)
        );
        $context->builder->branchIf($isEnumCase, $enumBlock, $afterEnum);

        $context->builder->positionAtEnd($enumBlock);
        $enumReal = self::lowerPhpInputFilterEnumCase($context, $valuePtr, $function, $param);
        $enumEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($afterEnum);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_INTEGER, false)
        );
        $context->builder->branchIf($isLong, $longBlock, $badBlock);

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $longEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($badBlock);
        self::emitTypeErrorAndAbort(
            $context,
            $function,
            $param,
            self::compileTimeEnumGivenLabel($context, $arg)
        );

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($i64);
        $phi->addIncoming($nullResult, $nullEnd);
        $phi->addIncoming($enumReal, $enumEnd);
        $phi->addIncoming($longVal, $longEnd);

        return $phi;
    }

    private static function lowerRuntimeNull(Context $context, string $function, string $param): Value
    {
        $i64 = $context->getTypeFromString('int64');
        if ($context->callerStrictTypes) {
            self::emitTypeErrorAndAbort($context, $function, $param, 'null');
            BasicBlockHelper::ensureOpenInsertBlock($context, 'jit_filter_input_type_rt_null_strict');

            return $i64->constInt(0, false);
        }
        JitIntdiv::emitNullIntDeprecation($context, $function, 1, $param, 'int');

        return $i64->constInt(0, false);
    }

    private static function lowerPhpInputFilterEnumCase(
        Context $context,
        Value $valuePtr,
        string $function,
        string $param
    ): Value {
        $classId = self::readEnumClassId($context, $valuePtr);
        $phpInputFilterId = $context->type->object->phpInputFilterEnumClassId();
        if (null === $phpInputFilterId) {
            self::emitTypeErrorAndAbort($context, $function, $param, 'object');

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        $i32 = $context->getTypeFromString('int32');
        $isPhpInputFilter = $context->builder->icmp(
            Builder::INT_EQ,
            $classId,
            $i32->constInt($phpInputFilterId, false)
        );
        $okBlock = BasicBlockHelper::append($context, 'jit_filter_input_type_enum_ok');
        $badBlock = BasicBlockHelper::append($context, 'jit_filter_input_type_enum_bad');
        $context->builder->branchIf($isPhpInputFilter, $okBlock, $badBlock);
        $context->builder->positionAtEnd($badBlock);
        self::emitTypeErrorAndAbort($context, $function, $param, 'object');
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

    private static function emitTypeErrorAndAbort(
        Context $context,
        string $function,
        string $param,
        string $given
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            sprintf(
                '%s(): Argument #1 ($%s) must be of type PhpInputFilter|int, %s given',
                $function,
                $param,
                $given
            )
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
