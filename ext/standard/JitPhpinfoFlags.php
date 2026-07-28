<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** JIT flag coercion for phpinfo()/phpcredits() (#5304, #7285). */
final class JitPhpinfoFlags
{
    public static function resolvePhpinfoFlags(Context $context, ?JITVariable $arg): Value
    {
        $i32 = $context->getTypeFromString('int32');
        if (null === $arg) {
            return $i32->constInt(VmInfo::allFlagsI32(), true);
        }
        $compileTime = self::compileTimePhpinfoFlags($context, $arg);
        if (null !== $compileTime) {
            return $i32->constInt($compileTime, true);
        }

        return self::lowerRuntimePhpinfoFlags($context, $arg);
    }

    public static function resolvePhpcreditsFlags(Context $context, ?JITVariable $arg): Value
    {
        $i32 = $context->getTypeFromString('int32');
        if (null === $arg) {
            return $i32->constInt(VmInfo::allFlagsI32(), true);
        }
        $compileTime = self::compileTimePhpcreditsFlags($context, $arg);
        if (null !== $compileTime) {
            return $i32->constInt($compileTime, true);
        }

        return self::lowerRuntimePhpcreditsFlags($context, $arg);
    }

    private static function compileTimePhpinfoFlags(Context $context, JITVariable $arg): ?int
    {
        $fromEnum = self::compileTimeInfoViewBacking($context, $arg);
        if (null !== $fromEnum) {
            return $fromEnum;
        }
        if ($arg->isNullConstant) {
            return VmInfo::INFO_ALL;
        }
        if (null === $arg->compileTimeConstantName || null === $context->runtime->vmContext) {
            return null;
        }
        $phpVar = $context->runtime->vmContext->constantFetch($arg->compileTimeConstantName);
        if (null === $phpVar) {
            return null;
        }

        return VmInfo::resolvePhpinfoFlagsArg($phpVar);
    }

    private static function compileTimePhpcreditsFlags(Context $context, JITVariable $arg): ?int
    {
        if ($arg->isNullConstant) {
            return VmInfo::CREDITS_ALL;
        }
        if (null === $arg->compileTimeConstantName || null === $context->runtime->vmContext) {
            return null;
        }
        $phpVar = $context->runtime->vmContext->constantFetch($arg->compileTimeConstantName);
        if (null === $phpVar) {
            return null;
        }
        $resolved = $phpVar->resolveIndirect();
        if (VmVariable::TYPE_NULL === $resolved->type) {
            return VmInfo::CREDITS_ALL;
        }
        if (VmVariable::TYPE_INTEGER !== $resolved->type && VmVariable::TYPE_FLOAT !== $resolved->type) {
            return null;
        }

        return (int) $resolved->toInt();
    }

    private static function compileTimeInfoViewBacking(Context $context, JITVariable $arg): ?int
    {
        if (null === $arg->compileTimeEnumCase) {
            return null;
        }
        $jitObject = $context->type->object;
        if (!$jitObject instanceof \PHPCompiler\JIT\Builtin\Type\Object_) {
            return null;
        }
        $classId = $arg->compileTimeEnumCase['classId'];
        $caseKey = $arg->compileTimeEnumCase['caseKey'];
        if ('infoview' !== strtolower(ltrim($jitObject->classNameForId($classId), '\\'))) {
            return null;
        }
        $backing = $jitObject->enumCaseBackingScalarForCase($classId, $caseKey);
        if (!\is_int($backing)) {
            throw new \LogicException('InfoView case missing int backing');
        }

        return $backing;
    }

    private static function lowerRuntimePhpinfoFlags(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->builder->trunc($context->helper->loadValue($arg), $context->getTypeFromString('int32'));
        }
        if (JITVariable::TYPE_VALUE !== $arg->type) {
            self::emitPhpinfoTypeError($context, 'mixed');
            $i32 = $context->getTypeFromString('int32');

            return $i32->constInt(VmInfo::allFlagsI32(), true);
        }

        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');

        $nullBlock = BasicBlockHelper::append($context, 'phpinfo_flags_null');
        $afterNull = BasicBlockHelper::append($context, 'phpinfo_flags_after_null');
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_NULL, false)
        );
        $context->builder->branchIf($isNull, $nullBlock, $afterNull);
        $context->builder->positionAtEnd($nullBlock);
        $nullVal = $i32->constInt(VmInfo::allFlagsI32(), true);
        $nullEnd = $context->builder->getInsertBlock();
        $context->builder->branch($afterNull);
        $context->builder->positionAtEnd($afterNull);

        $enumBlock = BasicBlockHelper::append($context, 'phpinfo_flags_enum');
        $afterEnum = BasicBlockHelper::append($context, 'phpinfo_flags_after_enum');
        $isEnum = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_ENUM_CASE, false)
        );
        $context->builder->branchIf($isEnum, $enumBlock, $afterEnum);
        $context->builder->positionAtEnd($enumBlock);
        $enumVal = self::lowerInfoViewEnumCase($context, $valuePtr);
        $enumEnd = $context->builder->getInsertBlock();
        $context->builder->branch($afterEnum);
        $context->builder->positionAtEnd($afterEnum);

        $intBlock = BasicBlockHelper::append($context, 'phpinfo_flags_int');
        $badBlock = BasicBlockHelper::append($context, 'phpinfo_flags_bad');
        $mergeBlock = BasicBlockHelper::append($context, 'phpinfo_flags_merge');
        $isInt = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(VmVariable::TYPE_INTEGER, false)),
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(VmVariable::TYPE_FLOAT, false))
        );
        $context->builder->branchIf($isInt, $intBlock, $badBlock);
        $context->builder->positionAtEnd($intBlock);
        $intVal = $context->builder->trunc(
            $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr),
            $i32
        );
        $intEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);
        $context->builder->positionAtEnd($badBlock);
        self::emitPhpinfoTypeError($context, self::runtimeTypeLabel($context, $arg));
        $badVal = $i32->constInt(VmInfo::allFlagsI32(), true);
        $context->builder->branch($mergeBlock);
        $context->builder->positionAtEnd($mergeBlock);

        $phi = $context->builder->phi($i32);
        $phi->addIncoming($nullVal, $nullEnd);
        $phi->addIncoming($enumVal, $enumEnd);
        $phi->addIncoming($intVal, $intEnd);
        $phi->addIncoming($badVal, $badBlock);

        return $phi;
    }

    private static function lowerRuntimePhpcreditsFlags(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->builder->trunc($context->helper->loadValue($arg), $context->getTypeFromString('int32'));
        }
        if (JITVariable::TYPE_VALUE !== $arg->type) {
            self::emitPhpcreditsTypeError($context, 'mixed');
            $i32 = $context->getTypeFromString('int32');

            return $i32->constInt(VmInfo::allFlagsI32(), true);
        }

        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');

        $nullBlock = BasicBlockHelper::append($context, 'phpcredits_flags_null');
        $afterNull = BasicBlockHelper::append($context, 'phpcredits_flags_after_null');
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_NULL, false)
        );
        $context->builder->branchIf($isNull, $nullBlock, $afterNull);
        $context->builder->positionAtEnd($nullBlock);
        $nullVal = $i32->constInt(VmInfo::allFlagsI32(), true);
        $nullEnd = $context->builder->getInsertBlock();
        $context->builder->branch($afterNull);
        $context->builder->positionAtEnd($afterNull);

        $intBlock = BasicBlockHelper::append($context, 'phpcredits_flags_int');
        $badBlock = BasicBlockHelper::append($context, 'phpcredits_flags_bad');
        $mergeBlock = BasicBlockHelper::append($context, 'phpcredits_flags_merge');
        $isInt = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(VmVariable::TYPE_INTEGER, false)),
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(VmVariable::TYPE_FLOAT, false))
        );
        $context->builder->branchIf($isInt, $intBlock, $badBlock);
        $context->builder->positionAtEnd($intBlock);
        $intVal = $context->builder->trunc(
            $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr),
            $i32
        );
        $intEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);
        $context->builder->positionAtEnd($badBlock);
        self::emitPhpcreditsTypeError($context, self::runtimeTypeLabel($context, $arg));
        $badVal = $i32->constInt(VmInfo::allFlagsI32(), true);
        $context->builder->branch($mergeBlock);
        $context->builder->positionAtEnd($mergeBlock);

        $phi = $context->builder->phi($i32);
        $phi->addIncoming($nullVal, $nullEnd);
        $phi->addIncoming($intVal, $intEnd);
        $phi->addIncoming($badVal, $badBlock);

        return $phi;
    }

    private static function lowerInfoViewEnumCase(Context $context, Value $valuePtr): Value
    {
        $enumMap = $context->structFieldMap['__enum_case__'] ?? null;
        $i32 = $context->getTypeFromString('int32');
        if (null === $enumMap || !isset($enumMap['class_id'])) {
            self::emitPhpinfoTypeError($context, 'object');

            return $i32->constInt(VmInfo::allFlagsI32(), true);
        }
        $classId = $context->builder->load(
            $context->builder->structGep($valuePtr, $enumMap['class_id'])
        );
        $infoViewId = $context->type->object->infoViewEnumClassId();
        if (null === $infoViewId) {
            self::emitPhpinfoTypeError($context, 'object');

            return $i32->constInt(VmInfo::allFlagsI32(), true);
        }
        $okBlock = BasicBlockHelper::append($context, 'phpinfo_infoview_ok');
        $badBlock = BasicBlockHelper::append($context, 'phpinfo_infoview_bad');
        $isInfoView = $context->builder->icmp(
            Builder::INT_EQ,
            $classId,
            $i32->constInt($infoViewId, false)
        );
        $context->builder->branchIf($isInfoView, $okBlock, $badBlock);
        $context->builder->positionAtEnd($badBlock);
        self::emitPhpinfoTypeError($context, 'object');
        $context->builder->positionAtEnd($okBlock);

        return $context->builder->trunc(
            $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr),
            $i32
        );
    }

    private static function emitPhpinfoTypeError(Context $context, string $given): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            'phpinfo(): Argument #1 ($flags) must be of type InfoView|int|null, '.$given.' given'
        );
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function emitPhpcreditsTypeError(Context $context, string $given): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            'phpcredits(): Argument #1 ($flags) must be of type int|null, '.$given.' given'
        );
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function runtimeTypeLabel(Context $context, JITVariable $arg): string
    {
        if (JITVariable::KIND_VALUE !== $arg->kind) {
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
        $label = JitOperandTypeLabel::compileTimeEnumClassName($context, $arg);

        return $label ?? 'object';
    }
}
