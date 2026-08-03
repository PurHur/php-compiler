<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TimezoneOffsetRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for timezone_offset_get() / DateTimeZone::getOffset() (#6041, #27308).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(timezone_offset_get) / zim_DateTimeZone_getOffset
 *
 * Prefer compile-time materialize when zone name + DateTime timestamp are folded
 * (construct leaves both on the receivers; peer getName/getTransitions).
 */
final class JitTimezoneOffsetGet
{
    private const ZONE_TYPE_ERROR =
        'timezone_offset_get(): Argument #1 ($object) must be of type DateTimeZone, %s given';

    private const DATETIME_TYPE_ERROR =
        'timezone_offset_get(): Argument #2 ($datetime) must be of type DateTimeInterface, %s given';

    private const METHOD_ZONE_TYPE_ERROR =
        'DateTimeZone::getOffset(): Argument #1 ($object) must be of type DateTimeZone, %s given';

    private const METHOD_DATETIME_TYPE_ERROR =
        'DateTimeZone::getOffset(): Argument #1 ($datetime) must be of type DateTimeInterface, %s given';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \ArgumentCountError(
                \sprintf('timezone_offset_get() expects exactly 2 arguments, %d given', \count($args))
            );
        }

        return self::lower(
            $context,
            self::ZONE_TYPE_ERROR,
            self::DATETIME_TYPE_ERROR,
            $args[0],
            $args[1]
        );
    }

    /** DateTimeZone::getOffset($this, $datetime) — same ABI as procedural (#27308). */
    public static function invokeMethod(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \ArgumentCountError(
                \sprintf('DateTimeZone::getOffset() expects exactly 1 argument, %d given', max(0, \count($args) - 1))
            );
        }

        return self::lower(
            $context,
            self::METHOD_ZONE_TYPE_ERROR,
            self::METHOD_DATETIME_TYPE_ERROR,
            $args[0],
            $args[1]
        );
    }

    private static function lower(
        Context $context,
        string $zoneTypeError,
        string $datetimeTypeError,
        JITVariable $zoneArg,
        JITVariable $datetimeArg
    ): Value {
        $zoneName = self::tryCompileTimeZoneName($context, $zoneArg);
        $timestamp = self::tryCompileTimeTimestamp($datetimeArg);
        if (null !== $zoneName && null !== $timestamp) {
            $offset = VmDateTimeNative::timezoneOffsetSeconds($zoneName, $timestamp);
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                $ptr,
                $context->getTypeFromString('int64')->constInt($offset, true)
            );

            return $ptr;
        }

        TimezoneOffsetRuntime::ensureLinked($context);

        $zoneObj = self::requireDateTimeZoneObject($context, $zoneArg, $zoneTypeError);
        $dtObj = self::requireDateTimeInterfaceObject($context, $datetimeArg, $datetimeTypeError);
        /** @var ObjectBuiltin $object */
        $object = $context->type->object;

        // TYPE_STRING props are __string__* slots — do not load before the offset ABI (#27307/#27308).
        $zoneNamePtr = JitTimezoneProceduralArg::readStringPropPtr(
            $context,
            $object,
            $zoneObj,
            'DateTimeZone',
            DateTimeSupport::TZ_NAME_PROPERTY
        );
        $ts = self::readTimestampProp($context, $object, $dtObj);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__phpc_timezone_offset_seconds'),
            $zoneNamePtr,
            $ts,
            $ptr
        );

        return $ptr;
    }

    private static function tryCompileTimeZoneName(Context $context, JITVariable $arg): ?string
    {
        if (null !== $arg->compileTimeString && '' !== $arg->compileTimeString) {
            return $arg->compileTimeString;
        }

        $literal = JitStringBuiltinArg::compileTimeLiteral($arg);
        if (null !== $literal && '' !== $literal) {
            return $literal;
        }

        if (JITVariable::TYPE_OBJECT === $arg->type) {
            /** @var ObjectBuiltin $object */
            $object = $context->type->object;
            $prop = $object->propertyFetch($arg->value, 'DateTimeZone', DateTimeSupport::TZ_NAME_PROPERTY);
            if (JITVariable::TYPE_STRING === $prop->type && null !== ($prop->compileTimeString ?? null)) {
                return $prop->compileTimeString;
            }
        }

        return null;
    }

    private static function tryCompileTimeTimestamp(JITVariable $arg): ?int
    {
        if (null !== $arg->compileTimeLong) {
            return (int) $arg->compileTimeLong;
        }

        return null;
    }

    private static function requireDateTimeZoneObject(
        Context $context,
        JITVariable $arg,
        string $typeError = self::ZONE_TYPE_ERROR
    ): Value {
        $objPtr = self::requireObjectValue($context, $arg, $typeError, 'array');
        self::assertClassOneOf($context, $objPtr, ['DateTimeZone'], $typeError, 'object');

        return $objPtr;
    }

    private static function requireDateTimeInterfaceObject(
        Context $context,
        JITVariable $arg,
        string $typeError = self::DATETIME_TYPE_ERROR
    ): Value {
        $objPtr = self::requireObjectValue($context, $arg, $typeError, 'array');
        self::assertClassOneOf(
            $context,
            $objPtr,
            ['DateTime', 'DateTimeImmutable'],
            $typeError,
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
