<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TimezoneOffsetRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for timezone_offset_get() (#6041 phase 2, ext/date/php_date.c). */
final class JitTimezoneOffsetGet
{
    private const ZONE_TYPE_ERROR =
        'timezone_offset_get(): Argument #1 ($object) must be of type DateTimeZone, %s given';

    private const DATETIME_TYPE_ERROR =
        'timezone_offset_get(): Argument #2 ($datetime) must be of type DateTimeInterface, %s given';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \ArgumentCountError(
                \sprintf('timezone_offset_get() expects exactly 2 arguments, %d given', \count($args))
            );
        }

        TimezoneOffsetRuntime::ensureLinked($context);

        $zoneObj = self::requireDateTimeZoneObject($context, $args[0]);
        $dtObj = self::requireDateTimeInterfaceObject($context, $args[1]);
        /** @var ObjectBuiltin $object */
        $object = $context->type->object;

        $zoneName = self::readStringProp(
            $context,
            $object,
            $zoneObj,
            'DateTimeZone',
            DateTimeSupport::TZ_NAME_PROPERTY
        );
        $timestamp = self::readTimestampProp($context, $object, $dtObj);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__phpc_timezone_offset_seconds'),
            $zoneName,
            $timestamp,
            $ptr
        );

        return $ptr;
    }

    private static function requireDateTimeZoneObject(Context $context, JITVariable $arg): Value
    {
        $objPtr = self::requireObjectValue($context, $arg, self::ZONE_TYPE_ERROR, 'array');
        self::assertClassOneOf($context, $objPtr, ['DateTimeZone'], self::ZONE_TYPE_ERROR, 'object');

        return $objPtr;
    }

    private static function requireDateTimeInterfaceObject(Context $context, JITVariable $arg): Value
    {
        $objPtr = self::requireObjectValue($context, $arg, self::DATETIME_TYPE_ERROR, 'array');
        self::assertClassOneOf(
            $context,
            $objPtr,
            ['DateTime', 'DateTimeImmutable'],
            self::DATETIME_TYPE_ERROR,
            'object'
        );

        return $objPtr;
    }

    private static function requireObjectValue(
        Context $context,
        JITVariable $arg,
        string $typeErrorTemplate,
        string $nonObjectGiven
    ): Value {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return $arg->value;
        }
        if (JITVariable::TYPE_VALUE !== $arg->type) {
            self::emitTypeErrorAndAbort($context, \sprintf($typeErrorTemplate, self::typeLabel($arg->type)));

            return $context->getTypeFromString('__object__*')->constNull();
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
        $okBlock = BasicBlockHelper::append($context, 'tzoff_obj_ok');
        $errBlock = BasicBlockHelper::append($context, 'tzoff_obj_err');
        $context->builder->branchIf($isObject, $okBlock, $errBlock);

        $context->builder->positionAtEnd($errBlock);
        self::emitTypeErrorAndAbort($context, \sprintf($typeErrorTemplate, $nonObjectGiven));

        $context->builder->positionAtEnd($okBlock);

        return $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
    }

    /**
     * @param list<string> $classNames
     */
    private static function assertClassOneOf(
        Context $context,
        Value $objPtr,
        array $classNames,
        string $typeErrorTemplate,
        string $wrongGiven
    ): void {
        /** @var ObjectBuiltin $object */
        $object = $context->type->object;
        $map = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($objPtr, $map['class_id'])
        );
        $i64 = $context->getTypeFromString('int64');
        $tag = 'tzoff_class_'.spl_object_id($context);

        $failBlock = BasicBlockHelper::append($context, $tag.'_fail');
        $okBlock = BasicBlockHelper::append($context, $tag.'_ok');

        $last = \count($classNames) - 1;
        foreach ($classNames as $idx => $className) {
            $expectedId = $object->lookup($className);
            $matches = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $i64->constInt($expectedId, false)
            );
            $nextBlock = $last === $idx
                ? $failBlock
                : BasicBlockHelper::append($context, $tag.'_next_'.$idx);
            $context->builder->branchIf($matches, $okBlock, $nextBlock);
            $context->builder->positionAtEnd($nextBlock);
        }

        $context->builder->positionAtEnd($failBlock);
        self::emitTypeErrorAndAbort($context, \sprintf($typeErrorTemplate, $wrongGiven));

        $context->builder->positionAtEnd($okBlock);
    }

    private static function readStringProp(
        Context $context,
        ObjectBuiltin $object,
        Value $objPtr,
        string $className,
        string $propName
    ): Value {
        $prop = $object->propertyFetch($objPtr, $className, $propName);
        if (JITVariable::TYPE_STRING === $prop->type) {
            return $context->builder->load($prop->value);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $prop->value
        );
    }

    private static function readTimestampProp(
        Context $context,
        ObjectBuiltin $object,
        Value $objPtr
    ): Value {
        $map = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($objPtr, $map['class_id'])
        );
        $i64 = $context->getTypeFromString('int64');
        $dtId = $object->lookup('DateTime');
        $dtiId = $object->lookup('DateTimeImmutable');
        $isDt = $context->builder->icmp(
            Builder::INT_EQ,
            $classId,
            $i64->constInt($dtId, false)
        );
        $dtBlock = BasicBlockHelper::append($context, 'tzoff_ts_dt');
        $dtiBlock = BasicBlockHelper::append($context, 'tzoff_ts_dti');
        $doneBlock = BasicBlockHelper::append($context, 'tzoff_ts_done');
        $context->builder->branchIf($isDt, $dtBlock, $dtiBlock);

        $context->builder->positionAtEnd($dtBlock);
        $dtTs = self::readLongProp($context, $object, $objPtr, 'DateTime', DateTimeSupport::TS_PROPERTY);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($dtiBlock);
        $isDti = $context->builder->icmp(
            Builder::INT_EQ,
            $classId,
            $i64->constInt($dtiId, false)
        );
        $failBlock = BasicBlockHelper::append($context, 'tzoff_ts_fail');
        $readBlock = BasicBlockHelper::append($context, 'tzoff_ts_read_dti');
        $context->builder->branchIf($isDti, $readBlock, $failBlock);

        $context->builder->positionAtEnd($failBlock);
        self::emitTypeErrorAndAbort($context, \sprintf(self::DATETIME_TYPE_ERROR, 'object'));

        $context->builder->positionAtEnd($readBlock);
        $dtiTs = self::readLongProp($context, $object, $objPtr, 'DateTimeImmutable', DateTimeSupport::TS_PROPERTY);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i64);
        $phi->addIncoming($dtTs, $dtBlock);
        $phi->addIncoming($dtiTs, $readBlock);

        return $phi;
    }

    private static function readLongProp(
        Context $context,
        ObjectBuiltin $object,
        Value $objPtr,
        string $className,
        string $propName
    ): Value {
        $prop = $object->propertyFetch($objPtr, $className, $propName);
        if (JITVariable::TYPE_NATIVE_LONG === $prop->type) {
            return $context->builder->load($prop->value);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $prop->value
        );
    }

    private static function emitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function typeLabel(int $type): string
    {
        return match ($type) {
            JITVariable::TYPE_NATIVE_LONG => 'int',
            JITVariable::TYPE_NATIVE_DOUBLE => 'float',
            JITVariable::TYPE_NATIVE_BOOL => 'bool',
            JITVariable::TYPE_STRING => 'string',
            JITVariable::TYPE_NULL => 'null',
            default => 'mixed',
        };
    }
}
