<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\calendar\CalInfoJitHelper;
use PHPCompiler\ext\calendar\CalendarConstants;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPLLVM\Value;

/**
 * JIT/AOT link for cal_info() (#27354).
 *
 * NestedJIT of {@see CalInfoJitHelper} returns a PHP {@see \PHPCompiler\VM\HashTable}
 * that is not a thin-AOT `__hashtable__` (empty dim results — peer range #26956 /
 * array_keys NestedJIT #20533). Embed via {@see HashTableHelper::variableFromVmHashTable}
 * from host-side {@see CalInfoJitHelper} / {@see \PHPCompiler\ext\calendar\VmCalendar}.
 *
 * SSOT: {@see CalInfoJitHelper} → {@see \PHPCompiler\ext\calendar\VmCalendar}
 * php-src: ext/calendar/calendar.c — PHP_FUNCTION(cal_info)
 */
final class CalInfoRuntime
{
    private const ABI = 'phpc_cal_info';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    /** Compile-time calendar id — embed Gregorian/Julian/Jewish/French meta table. */
    public static function emitOne(Context $context, int $calendar): Value
    {
        $ht = CalInfoJitHelper::calInfoArgv($calendar);

        return HashTableHelper::variableFromVmHashTable($context, $ht)->value;
    }

    /** Compile-time cal_info() with no args — all calendars. */
    public static function emitAll(Context $context): Value
    {
        $ht = CalInfoJitHelper::calInfoAllArgv();

        return HashTableHelper::variableFromVmHashTable($context, $ht)->value;
    }

    public static function invoke(Context $context, Value $calendar): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $calendar
        );
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinked($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($htPtr, false, $i64)
            );

        $entry = $fn->appendBasicBlock('cal_info_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $cal = $fn->getParam(0);

        $defaultBb = BasicBlockHelper::append($context, 'cal_info_invalid');
        $allBb = BasicBlockHelper::append($context, 'cal_info_all');
        $mergeBb = BasicBlockHelper::append($context, 'cal_info_merge');
        $resultSlot = $context->builder->alloca($htPtr, 1, 'cal_info_result');

        $caseBlocks = [];
        for ($id = 0; $id < CalendarConstants::CAL_NUM_CALS; ++$id) {
            $caseBlocks[$id] = BasicBlockHelper::append($context, 'cal_info_case_'.$id);
        }

        // Cases: 0..CAL_NUM_CALS-1 + all-calendars sentinel -1 (#28907)
        $switch = $context->builder->branchSwitch(
            $cal,
            $defaultBb,
            CalendarConstants::CAL_NUM_CALS + 1
        );
        for ($id = 0; $id < CalendarConstants::CAL_NUM_CALS; ++$id) {
            $switch->addCase($i64->constInt($id, false), $caseBlocks[$id]);
        }
        $switch->addCase($i64->constInt(-1, true), $allBb);

        foreach ($caseBlocks as $id => $bb) {
            $context->builder->positionAtEnd($bb);
            $embedded = self::emitOne($context, $id);
            $context->builder->store($embedded, $resultSlot);
            $context->builder->branch($mergeBb);
        }

        $context->builder->positionAtEnd($allBb);
        $embeddedAll = self::emitAll($context);
        $context->builder->store($embeddedAll, $resultSlot);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($defaultBb);
        TypeErrorRaise::emitValueError(
            $context,
            'cal_info(): Argument #1 ($calendar) must be a valid calendar ID'
        );
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_type_error'));
        } else {
            $context->builder->call($context->lookupFunction('abort'));
        }
        // Unreachable after abort — still return null HT for well-formed IR.
        $context->builder->returnValue($htPtr->constNull());

        $context->builder->positionAtEnd($mergeBb);
        $context->builder->returnValue($context->builder->load($resultSlot));

        self::registerLinked($context);

        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function registerLinked(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI.' missing after CalInfoRuntime bridge (#27354)');
        }
        $context->registerFunction(self::ABI, $fn);
    }
}
