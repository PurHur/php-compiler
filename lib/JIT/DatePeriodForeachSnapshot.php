<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\DateTimeSupport;
use PHPLLVM\Value;

/**
 * Thin-AOT DatePeriod foreach via compile-time timestamp snapshot → hashtable (#26772).
 */
final class DatePeriodForeachSnapshot
{
    public static function canLower(Variable $array): bool
    {
        return null !== $array->compileTimeDatePeriodTimestamps;
    }

    public static function compileReset(Context $context, Variable $array, Variable $slotKey): void
    {
        $timestamps = $array->compileTimeDatePeriodTimestamps;
        if (null === $timestamps) {
            throw new \LogicException('DatePeriod foreach snapshot missing timestamps (#26772)');
        }
        $tz = $array->compileTimeDatePeriodTimezone ?? 'UTC';
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $sizeT = $context->getTypeFromString('size_t');
        $n = \count($timestamps);
        $context->builder->call(
            $context->lookupFunction('__hashtable__grow'),
            $ht,
            $sizeT->constInt($n > 0 ? $n : 1, false)
        );
        foreach ($timestamps as $index => $ts) {
            $obj = self::makeDateTimeImmutable($context, (int) $ts, $tz);
            $elem = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
            HashTableHelper::setAtIndex($context, $ht, $sizeT->constInt((int) $index, false), $elem);
        }
        $map = $context->structFieldMap['__hashtable__'];
        $context->builder->store(
            $sizeT->constInt($n, false),
            $context->builder->structGep($ht, $map['numElements'])
        );
        $context->builder->store(
            $sizeT->constInt($n, false),
            $context->builder->structGep($ht, $map['nextFreeElement'])
        );

        $htVar = new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $ht);
        $key = $context->foreachSlotMapKey($slotKey);
        $context->foreachDatePeriodSnapshotHts[$key] = $htVar;

        if (!isset($context->foreachIndexSlots[$key])) {
            $context->foreachIndexSlots[$key] = BasicBlockHelper::entryAlloca($context, $sizeT);
        }
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $invalid = $context->builder->sub($zero, $one);
        $context->builder->store($invalid, $context->foreachIndexSlots[$key]);
    }

    private static function makeDateTimeImmutable(Context $context, int $timestamp, string $tz): Value
    {
        $objectType = $context->type->object;
        $className = 'DateTimeImmutable';
        $classId = $objectType->lookup($className);
        $obj = $objectType->allocate($classId);
        $i64 = $context->getTypeFromString('int64');
        $voidPtr = $context->getTypeFromString('void*');

        $tsPtr = $context->memory->malloc($i64);
        $context->builder->store($i64->constInt($timestamp, false), $tsPtr);
        $context->builder->store(
            $context->builder->pointerCast($tsPtr, $voidPtr),
            $objectType->propertySlotFor($obj, $className, DateTimeSupport::TS_PROPERTY)
        );

        $usPtr = $context->memory->malloc($i64);
        $context->builder->store($i64->constInt(0, false), $usPtr);
        $context->builder->store(
            $context->builder->pointerCast($usPtr, $voidPtr),
            $objectType->propertySlotFor($obj, $className, DateTimeSupport::MICROSECOND_PROPERTY)
        );

        $tzStr = $context->builder->load($context->constantStringFromString($tz));
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $tzStr);
        $context->builder->store(
            $context->builder->pointerCast($owned, $voidPtr),
            $objectType->propertySlotFor($obj, $className, DateTimeSupport::TZ_PROPERTY)
        );

        $objectType->markObjectConstructed($obj);

        return $obj;
    }

    public static function hashtableFor(Context $context, Variable $slotKey): Variable
    {
        $key = $context->foreachSlotMapKey($slotKey);
        if (!isset($context->foreachDatePeriodSnapshotHts[$key])) {
            throw new \LogicException('DatePeriod foreach snapshot HT missing after RESET (#26772)');
        }

        return $context->foreachDatePeriodSnapshotHts[$key];
    }
}
