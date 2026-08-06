<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT DatePeriod Iterator protocol — PHP SSOT (#14228, #16796, ext/date/php_date.c).
 */
final class DatePeriodIteratorJitHelper
{
    private const CLASS_PERIOD = 'DatePeriod';

    private const CLASS_DATETIME = 'DateTimeImmutable';

    private const CLASS_INTERVAL = 'DateInterval';

    public static function compileRewind(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $objectType = $context->type->object;
        $startSlot = $objectType->propertyFetch($obj, self::CLASS_PERIOD, 'start');
        $currentObj = self::cloneDateTimeObject($context, $startSlot);
        self::storeObjectProperty($context, $obj, 'current', $currentObj);

        $includeStart = self::loadBoolProperty($context, $obj, 'include_start_date');
        $fn = $context->builder->getInsertBlock()->getParent();
        $skip = BasicBlockHelper::append($context, 'dp_rewind_skip');
        $done = BasicBlockHelper::append($context, 'dp_rewind_done');
        $context->builder->branchIf($includeStart, $done, $skip);
        $context->builder->positionAtEnd($skip);
        $currentSlot = $objectType->propertyFetch($obj, self::CLASS_PERIOD, 'current');
        $intervalSlot = $objectType->propertyFetch($obj, self::CLASS_PERIOD, 'interval');
        self::addIntervalToDateTime($context, $currentSlot, $intervalSlot);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);

        self::storeLongProperty($context, $obj, '__dp_iter_key', 0);
        self::storeBoolProperty($context, $obj, '__dp_iter_started', true);

        return self::voidResult($context);
    }

    public static function compileValid(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $started = self::loadBoolProperty($context, $obj, '__dp_iter_started');
        $fn = $context->builder->getInsertBlock()->getParent();
        $falseBb = BasicBlockHelper::append($context, 'dp_valid_false');
        $checkBb = BasicBlockHelper::append($context, 'dp_valid_check');
        $merge = BasicBlockHelper::append($context, 'dp_valid_merge');
        $context->builder->branchIf($started, $checkBb, $falseBb);

        $context->builder->positionAtEnd($checkBb);
        $objectType = $context->type->object;
        $currentSlot = $objectType->propertyFetch($obj, self::CLASS_PERIOD, 'current');
        $recurrences = self::loadLongProperty($context, $obj, 'recurrences');
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        // php-src date_period_it_has_more — end!=NULL selects end-date form (#22463, #27572).
        $endSlot = $objectType->propertyFetch($obj, self::CLASS_PERIOD, 'end');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $endPtr = self::loadObject($context, $endSlot);
        $isEndForm = $context->builder->icmp(
            Builder::INT_NE,
            $endPtr,
            $objPtrTy->constNull()
        );
        $endBb = BasicBlockHelper::append($context, 'dp_valid_end');
        $countBb = BasicBlockHelper::append($context, 'dp_valid_count');
        $context->builder->branchIf($isEndForm, $endBb, $countBb);

        $context->builder->positionAtEnd($endBb);
        $cmp = self::compareDateTimeSlots($context, $currentSlot, $endSlot);
        $includeEnd = self::loadBoolProperty($context, $obj, 'include_end_date');
        $le = $context->builder->icmp(Builder::INT_SLE, $cmp, $i64->constInt(0, false));
        $lt = $context->builder->icmp(Builder::INT_SLT, $cmp, $i64->constInt(0, false));
        $validEnd = $context->builder->select($includeEnd, $le, $lt);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($countBb);
        $key = self::loadLongProperty($context, $obj, '__dp_iter_key');
        // Stored count includes start slot; exclude-start yields userRecurrences only (#21939).
        $includeStartCount = self::loadBoolProperty($context, $obj, 'include_start_date');
        $limit = $context->builder->select(
            $includeStartCount,
            $recurrences,
            $context->builder->sub($recurrences, $i64->constInt(1, false))
        );
        $validCount = $context->builder->icmp(Builder::INT_SLT, $key, $limit);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($falseBb);
        $falseVal = $i1->constInt(0, false);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($validEnd, $endBb);
        $phi->addIncoming($validCount, $countBb);
        $phi->addIncoming($falseVal, $falseBb);

        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $phi);

        return $slot;
    }

    public static function compileCurrent(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $currentSlot = $context->type->object->propertyFetch($obj, self::CLASS_PERIOD, 'current');
        $cloned = self::cloneDateTimeVariable($context, $currentSlot);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            self::loadObject($context, $cloned)
        );

        return $slot;
    }

    public static function compileKey(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $key = self::loadLongProperty($context, $obj, '__dp_iter_key');
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $key);

        return $slot;
    }

    public static function compileNext(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $key = self::loadLongProperty($context, $obj, '__dp_iter_key');
        $i64 = $context->getTypeFromString('int64');
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, self::CLASS_PERIOD, '__dp_iter_key'),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $context->builder->addNoSignedWrap($key, $i64->constInt(1, false))
            ),
            JITVariable::TYPE_NATIVE_LONG
        );
        $currentSlot = $context->type->object->propertyFetch($obj, self::CLASS_PERIOD, 'current');
        $intervalSlot = $context->type->object->propertyFetch($obj, self::CLASS_PERIOD, 'interval');
        self::addIntervalToDateTime($context, $currentSlot, $intervalSlot);

        return self::voidResult($context);
    }

    /** DatePeriod::getStartDate() — clone of stored start (#27572 / #16614). */
    public static function compileGetStartDate(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $startSlot = $context->type->object->propertyFetch($obj, self::CLASS_PERIOD, 'start');
        $cloned = self::cloneDateTimeVariable($context, $startSlot);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            self::loadObject($context, $cloned)
        );

        return $slot;
    }

    /**
     * DatePeriod::getEndDate() — clone of stored end, or null for recurrence-count form (#27572 / #17495).
     */
    public static function compileGetEndDate(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $endSlot = $context->type->object->propertyFetch($obj, self::CLASS_PERIOD, 'end');
        $out = JitValueBox::alloc($context);
        $objPtrTy = $context->getTypeFromString('__object__*');
        $endPtr = self::loadObject($context, $endSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $endPtr, $objPtrTy->constNull());
        $nullBb = BasicBlockHelper::append($context, 'dp_getend_null');
        $objBb = BasicBlockHelper::append($context, 'dp_getend_obj');
        $done = BasicBlockHelper::append($context, 'dp_getend_done');
        $context->builder->branchIf($isNull, $nullBb, $objBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $out)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($objBb);
        $endObjVar = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $endPtr
        );
        $cloned = self::cloneDateTimeVariable($context, $endObjVar);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $out),
            self::loadObject($context, $cloned)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return $out;
    }

    /** DatePeriod::getDateInterval() — stored interval object (#27572 / #16614). */
    public static function compileGetDateInterval(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $intervalSlot = $context->type->object->propertyFetch($obj, self::CLASS_PERIOD, 'interval');
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            self::loadObject($context, $intervalSlot)
        );

        return $slot;
    }

    /**
     * DatePeriod::getRecurrences() — user count, or null for end-date form (#16614 / #22463).
     */
    public static function compileGetRecurrences(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $endSlot = $context->type->object->propertyFetch($obj, self::CLASS_PERIOD, 'end');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $i64 = $context->getTypeFromString('int64');
        $endPtr = self::loadObject($context, $endSlot);
        $isEndForm = $context->builder->icmp(
            Builder::INT_NE,
            $endPtr,
            $objPtrTy->constNull()
        );
        $out = JitValueBox::alloc($context);
        $nullBb = BasicBlockHelper::append($context, 'dp_getrec_null');
        $countBb = BasicBlockHelper::append($context, 'dp_getrec_count');
        $done = BasicBlockHelper::append($context, 'dp_getrec_done');
        $context->builder->branchIf($isEndForm, $nullBb, $countBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $out)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($countBb);
        // php-src stores userRecurrences+1; getter returns user count (#26852).
        $stored = self::loadLongProperty($context, $obj, 'recurrences');
        $user = $context->builder->sub($stored, $i64->constInt(1, false));
        JitValueBox::writeLong($context, $out, $user);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return $out;
    }

    private static function loadObject(Context $context, JITVariable $receiver): Value
    {
        if (JITVariable::TYPE_OBJECT === $receiver->type) {
            return $context->helper->loadValue($receiver);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            JitValueBox::valuePtrFromVariable($context, $receiver)
        );
    }

    private static function cloneDateTimeObject(Context $context, JITVariable $dateSlot): Value
    {
        $objectType = $context->type->object;
        $src = $context->helper->loadValue($dateSlot);
        $classId = $objectType->lookup(self::CLASS_DATETIME);
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);
        $i64 = $context->getTypeFromString('int64');
        foreach ([DateTimeSupport::TS_PROPERTY, DateTimeSupport::MICROSECOND_PROPERTY] as $prop) {
            $val = $objectType->propertyFetch($src, self::CLASS_DATETIME, $prop);
            $objectType->propertyStore(
                $objectType->propertySlotFor($obj, self::CLASS_DATETIME, $prop),
                $val,
                JITVariable::TYPE_NATIVE_LONG
            );
        }
        $tz = $objectType->propertyFetch($src, self::CLASS_DATETIME, DateTimeSupport::TZ_PROPERTY);
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_DATETIME, DateTimeSupport::TZ_PROPERTY),
            $tz,
            JITVariable::TYPE_STRING
        );

        return $obj;
    }

    private static function cloneDateTimeVariable(Context $context, JITVariable $dateSlot): JITVariable
    {
        $obj = self::cloneDateTimeObject($context, $dateSlot);

        return new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $obj);
    }

    private static function storeObjectProperty(Context $context, Value $periodObj, string $prop, Value $valueObj): void
    {
        // Object slots hold __object__* (peer JitDatePeriodConstruct #26772).
        $propVar = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $valueObj
        );
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($periodObj, self::CLASS_PERIOD, $prop),
            $propVar,
            JITVariable::TYPE_OBJECT
        );
    }

    private static function loadBoolProperty(Context $context, Value $obj, string $prop): Value
    {
        $var = $context->type->object->propertyFetch($obj, self::CLASS_PERIOD, $prop);

        return $context->helper->loadValue($var);
    }

    private static function loadLongProperty(Context $context, Value $obj, string $prop): Value
    {
        $var = $context->type->object->propertyFetch($obj, self::CLASS_PERIOD, $prop);

        return $context->helper->loadValue($var);
    }

    private static function longPropertySlot(Context $context, Value $obj, string $prop): Value
    {
        return $context->type->object->propertySlotFor($obj, self::CLASS_PERIOD, $prop)->objectPropertySlot;
    }

    private static function storeLongProperty(Context $context, Value $obj, string $prop, ?int $value): void
    {
        if (null === $value) {
            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, self::CLASS_PERIOD, $prop),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $i64->constInt($value, false)
            ),
            JITVariable::TYPE_NATIVE_LONG
        );
    }

    private static function storeBoolProperty(Context $context, Value $obj, string $prop, bool $value): void
    {
        $i1 = $context->getTypeFromString('int1');
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, self::CLASS_PERIOD, $prop),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_BOOL,
                JITVariable::KIND_VALUE,
                $i1->constInt($value ? 1 : 0, false)
            ),
            JITVariable::TYPE_NATIVE_BOOL
        );
    }

    private static function compareDateTimeSlots(Context $context, JITVariable $a, JITVariable $b): Value
    {
        $objectType = $context->type->object;
        $aObj = $context->helper->loadValue($a);
        $bObj = $context->helper->loadValue($b);
        $aTs = $context->helper->loadValue($objectType->propertyFetch($aObj, self::CLASS_DATETIME, DateTimeSupport::TS_PROPERTY));
        $bTs = $context->helper->loadValue($objectType->propertyFetch($bObj, self::CLASS_DATETIME, DateTimeSupport::TS_PROPERTY));

        return $context->builder->sub($aTs, $bTs);
    }

    private static function addIntervalToDateTime(Context $context, JITVariable $dateSlot, JITVariable $intervalSlot): void
    {
        $objectType = $context->type->object;
        $dateObj = $context->helper->loadValue($dateSlot);
        $intervalObj = $context->helper->loadValue($intervalSlot);
        $i64 = $context->getTypeFromString('int64');
        $tsSlot = $objectType->propertySlotFor($dateObj, self::CLASS_DATETIME, DateTimeSupport::TS_PROPERTY);
        $ts = $context->helper->loadValue($objectType->propertyFetch($dateObj, self::CLASS_DATETIME, DateTimeSupport::TS_PROPERTY));
        $days = $context->helper->loadValue($objectType->propertyFetch($intervalObj, self::CLASS_INTERVAL, 'd'));
        $hours = $context->helper->loadValue($objectType->propertyFetch($intervalObj, self::CLASS_INTERVAL, 'h'));
        $mins = $context->helper->loadValue($objectType->propertyFetch($intervalObj, self::CLASS_INTERVAL, 'i'));
        $secs = $context->helper->loadValue($objectType->propertyFetch($intervalObj, self::CLASS_INTERVAL, 's'));
        $delta = $context->builder->add(
            $context->builder->mul($days, $i64->constInt(86400, false)),
            $context->builder->add(
                $context->builder->mul($hours, $i64->constInt(3600, false)),
                $context->builder->add(
                    $context->builder->mul($mins, $i64->constInt(60, false)),
                    $secs
                )
            )
        );
        $invert = $context->helper->loadValue($objectType->propertyFetch($intervalObj, self::CLASS_INTERVAL, 'invert'));
        $isInverted = $context->builder->icmp(Builder::INT_NE, $invert, $i64->constInt(0, false));
        $added = $context->builder->add($ts, $delta);
        $subbed = $context->builder->sub($ts, $delta);
        $nextTs = $context->builder->select($isInverted, $subbed, $added);
        $objectType->propertyStore(
            $tsSlot,
            new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $nextTs),
            JITVariable::TYPE_NATIVE_LONG
        );
    }

    private static function voidResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
