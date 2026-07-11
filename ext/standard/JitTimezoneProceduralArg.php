<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** Shared DateTimeZone / DateTimeInterface guards for procedural timezone builtins (#6041). */
final class JitTimezoneProceduralArg
{
    public static function requireDateTimeZoneObject(
        Context $context,
        JITVariable $arg,
        string $typeErrorTemplate
    ): Value {
        $objPtr = self::requireObjectValue($context, $arg, $typeErrorTemplate, 'array');
        self::assertClassOneOf($context, $objPtr, ['DateTimeZone'], $typeErrorTemplate, 'object');

        return $objPtr;
    }

    public static function requireDateTimeInterfaceObject(
        Context $context,
        JITVariable $arg,
        string $typeErrorTemplate
    ): Value {
        $objPtr = self::requireObjectValue($context, $arg, $typeErrorTemplate, 'array');
        self::assertClassOneOf(
            $context,
            $objPtr,
            ['DateTime', 'DateTimeImmutable'],
            $typeErrorTemplate,
            'object'
        );

        return $objPtr;
    }

    public static function readStringProp(
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

    public static function readDateTimeTimezoneNameProp(
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
        $dtBlock = BasicBlockHelper::append($context, 'tzproc_tzname_dt');
        $dtiBlock = BasicBlockHelper::append($context, 'tzproc_tzname_dti');
        $doneBlock = BasicBlockHelper::append($context, 'tzproc_tzname_done');
        $context->builder->branchIf($isDt, $dtBlock, $dtiBlock);

        $context->builder->positionAtEnd($dtBlock);
        $dtName = self::readStringProp($context, $object, $objPtr, 'DateTime', DateTimeSupport::TZ_PROPERTY);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($dtiBlock);
        $isDti = $context->builder->icmp(
            Builder::INT_EQ,
            $classId,
            $i64->constInt($dtiId, false)
        );
        $failBlock = BasicBlockHelper::append($context, 'tzproc_tzname_fail');
        $readBlock = BasicBlockHelper::append($context, 'tzproc_tzname_read_dti');
        $context->builder->branchIf($isDti, $readBlock, $failBlock);

        $context->builder->positionAtEnd($failBlock);
        self::emitTypeErrorAndAbort(
            $context,
            'date_offset_get(): Argument #1 ($object) must be of type DateTimeInterface, object given'
        );

        $context->builder->positionAtEnd($readBlock);
        $dtiName = self::readStringProp($context, $object, $objPtr, 'DateTimeImmutable', DateTimeSupport::TZ_PROPERTY);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $stringType = $context->getTypeFromString('char*');
        $phi = $context->builder->phi($stringType);
        $phi->addIncoming($dtName, $dtBlock);
        $phi->addIncoming($dtiName, $readBlock);

        return $phi;
    }

    public static function readTimestampProp(Context $context, ObjectBuiltin $object, Value $objPtr): Value
    {
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
        $dtBlock = BasicBlockHelper::append($context, 'tzproc_ts_dt');
        $dtiBlock = BasicBlockHelper::append($context, 'tzproc_ts_dti');
        $doneBlock = BasicBlockHelper::append($context, 'tzproc_ts_done');
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
        $failBlock = BasicBlockHelper::append($context, 'tzproc_ts_fail');
        $readBlock = BasicBlockHelper::append($context, 'tzproc_ts_read_dti');
        $context->builder->branchIf($isDti, $readBlock, $failBlock);

        $context->builder->positionAtEnd($failBlock);
        self::emitTypeErrorAndAbort($context, 'timezone_offset_get(): Argument #2 ($datetime) must be of type DateTimeInterface, object given');

        $context->builder->positionAtEnd($readBlock);
        $dtiTs = self::readLongProp($context, $object, $objPtr, 'DateTimeImmutable', DateTimeSupport::TS_PROPERTY);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i64);
        $phi->addIncoming($dtTs, $dtBlock);
        $phi->addIncoming($dtiTs, $readBlock);

        return $phi;
    }

    public static function lowerOptionalIntArg(
        Context $context,
        ?JITVariable $arg,
        int $default,
        string $builtin,
        int $position,
        string $paramName
    ): Value {
        if (null === $arg) {
            $i64 = $context->getTypeFromString('int64');

            return $i64->constInt($default, false);
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->builder->load($arg->value);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
            $typeField = $context->structFieldMap['__value__']['type'];
            $typeByte = $context->builder->load(
                $context->builder->structGep($valuePtr, $typeField)
            );
            $i8 = $context->getTypeFromString('int8');
            $isLong = $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(VmVariable::TYPE_INTEGER, false)
            );
            $okBlock = BasicBlockHelper::append($context, 'tzproc_int_ok');
            $errBlock = BasicBlockHelper::append($context, 'tzproc_int_err');
            $context->builder->branchIf($isLong, $okBlock, $errBlock);

            $context->builder->positionAtEnd($errBlock);
            self::emitTypeErrorAndAbort(
                $context,
                \sprintf('%s(): Argument #%d ($%s) must be of type int, mixed given', $builtin, $position, $paramName)
            );

            $context->builder->positionAtEnd($okBlock);

            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $valuePtr
            );
        }

        throw new \LogicException(\sprintf('%s(): Argument #%d ($%s) must be an integer in this compiler build', $builtin, $position, $paramName));
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
        $okBlock = BasicBlockHelper::append($context, 'tzproc_obj_ok');
        $errBlock = BasicBlockHelper::append($context, 'tzproc_obj_err');
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
        $tag = 'tzproc_class_'.spl_object_id($context);

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

    public static function emitTypeErrorAndAbort(Context $context, string $message): void
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
