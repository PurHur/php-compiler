<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DateIntervalFormatRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for date_interval_format() (#7278 phase 2, ext/date/php_date.c).
 */
final class JitDateIntervalFormat
{
    private const CLASS_NAME = 'DateInterval';

    private const TYPE_ERROR =
        'date_interval_format(): Argument #1 ($object) must be of type DateInterval, %s given';

    public static function invoke(Context $context, JITVariable $intervalArg, JITVariable $formatArg): Value
    {
        DateIntervalFormatRuntime::ensureLinked($context);

        $format = JitStringBuiltinArg::lower(
            $context,
            $formatArg,
            'date_interval_format',
            2,
            'format'
        );
        $objPtr = self::requireDateIntervalObject($context, $intervalArg);
        /** @var ObjectBuiltin $object */
        $object = $context->type->object;

        $y = self::readLongProp($context, $object, $objPtr, 'y');
        $m = self::readLongProp($context, $object, $objPtr, 'm');
        $d = self::readLongProp($context, $object, $objPtr, 'd');
        $h = self::readLongProp($context, $object, $objPtr, 'h');
        $i = self::readLongProp($context, $object, $objPtr, 'i');
        $s = self::readLongProp($context, $object, $objPtr, 's');
        $f = self::readDoubleProp($context, $object, $objPtr, 'f');
        $invert = self::readLongProp($context, $object, $objPtr, 'invert');
        [$daysIsInt, $daysInt] = self::readDaysProp($context, $object, $objPtr);

        $result = $context->builder->call(
            $context->lookupFunction('__compiler_date_interval_format'),
            $y,
            $m,
            $d,
            $h,
            $i,
            $s,
            $f,
            $invert,
            $daysIsInt,
            $daysInt,
            $format
        );

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $result
        );

        return JitValueBox::pointer($context, $slot);
    }

    private static function requireDateIntervalObject(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            self::assertDateIntervalClass($context, $arg->value);

            return $arg->value;
        }
        if (JITVariable::TYPE_VALUE !== $arg->type) {
            self::emitTypeErrorAndAbort($context, self::formatTypeError(self::typeLabel($arg->type)));
            $objTy = $context->getTypeFromString('__object__*');

            return $objTy->constNull();
        }

        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $typeField = $context->structFieldMap['__value__']['type'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $typeField)
        );
        $i8 = $context->getTypeFromString('int8');
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_OBJECT, false)
        );
        $okBlock = BasicBlockHelper::append($context, 'di_fmt_obj_ok');
        $errBlock = BasicBlockHelper::append($context, 'di_fmt_obj_err');
        $context->builder->branchIf($isObject, $okBlock, $errBlock);

        $context->builder->positionAtEnd($errBlock);
        self::emitTypeErrorAndAbort($context, self::formatTypeError('array'));

        $context->builder->positionAtEnd($okBlock);
        $objPtr = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        self::assertDateIntervalClass($context, $objPtr);

        return $objPtr;
    }

    private static function assertDateIntervalClass(Context $context, Value $objPtr): void
    {
        /** @var ObjectBuiltin $object */
        $object = $context->type->object;
        $expectedId = $object->lookup(self::CLASS_NAME);
        $map = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($objPtr, $map['class_id'])
        );
        $i64 = $context->getTypeFromString('int64');
        $ok = $context->builder->icmp(
            Builder::INT_EQ,
            $classId,
            $i64->constInt($expectedId, false)
        );
        $matchBlock = BasicBlockHelper::append($context, 'di_fmt_class_ok');
        $failBlock = BasicBlockHelper::append($context, 'di_fmt_class_fail');
        $context->builder->branchIf($ok, $matchBlock, $failBlock);

        $context->builder->positionAtEnd($failBlock);
        self::emitTypeErrorAndAbort($context, self::formatTypeError('object'));

        $context->builder->positionAtEnd($matchBlock);
    }

    private static function readLongProp(
        Context $context,
        ObjectBuiltin $object,
        Value $objPtr,
        string $name
    ): Value {
        $prop = $object->propertyFetch($objPtr, self::CLASS_NAME, $name);
        if (JITVariable::TYPE_NATIVE_LONG === $prop->type) {
            return $context->builder->load($prop->value);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $prop->value
        );
    }

    private static function readDoubleProp(
        Context $context,
        ObjectBuiltin $object,
        Value $objPtr,
        string $name
    ): Value {
        $prop = $object->propertyFetch($objPtr, self::CLASS_NAME, $name);
        if (JITVariable::TYPE_NATIVE_DOUBLE === $prop->type) {
            return $context->builder->load($prop->value);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readDouble'),
            $prop->value
        );
    }

    /** @return array{0: Value, 1: Value} days_is_int flag and int value */
    private static function readDaysProp(
        Context $context,
        ObjectBuiltin $object,
        Value $objPtr
    ): array {
        $prop = $object->propertyFetch($objPtr, self::CLASS_NAME, 'days');
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($prop->value, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $isInt = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_INTEGER, false)
        );
        $intBlock = BasicBlockHelper::append($context, 'di_fmt_days_int');
        $boolBlock = BasicBlockHelper::append($context, 'di_fmt_days_bool');
        $mergeBlock = BasicBlockHelper::append($context, 'di_fmt_days_merge');
        $context->builder->branchIf($isInt, $intBlock, $boolBlock);

        $flagSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $valSlot = BasicBlockHelper::entryAlloca($context, $i64);

        $context->builder->positionAtEnd($intBlock);
        $context->builder->store($i64->constInt(1, false), $flagSlot);
        $context->builder->store(
            $context->builder->call($context->lookupFunction('__value__readLong'), $prop->value),
            $valSlot
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($boolBlock);
        $context->builder->store($i64->constInt(0, false), $flagSlot);
        $context->builder->store($i64->constInt(0, false), $valSlot);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return [$context->builder->load($flagSlot), $context->builder->load($valSlot)];
    }

    private static function emitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function formatTypeError(string $given): string
    {
        return \sprintf(self::TYPE_ERROR, $given);
    }

    private static function typeLabel(int $type): string
    {
        return match ($type) {
            JITVariable::TYPE_STRING => 'string',
            JITVariable::TYPE_NATIVE_LONG => 'int',
            JITVariable::TYPE_NATIVE_DOUBLE => 'float',
            JITVariable::TYPE_NATIVE_BOOL => 'bool',
            JITVariable::TYPE_NULL => 'null',
            default => 'array',
        };
    }
}
