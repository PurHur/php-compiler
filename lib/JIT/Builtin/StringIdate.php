<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM implementation of __compiler_idate — localtime + format char dispatch.
 *
 * Mirrors ext/standard/VmDate::idateValue() (issue #6830).
 * php-src: ext/date/php_date.c — PHP_FUNCTION(idate)
 */
final class StringIdate
{
    private const TM_SEC = 0;

    private const TM_MIN = 4;

    private const TM_HOUR = 8;

    private const TM_MDAY = 12;

    private const TM_MON = 16;

    private const TM_YEAR = 20;

    private const TM_WDAY = 24;

    private const TM_YDAY = 28;

    private const TM_ISDST = 32;

    private const ERR_FORMAT = -1;

    private const ERR_TOKEN = -2;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_idate');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($i64, false, $strPtr, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__compiler_idate', $ft);
        self::implementIdate($context, $fn);
        self::registerLinkedRuntime($context);
    }

    private static function implementIdate(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('idate_entry');
        $context->builder->positionAtEnd($entry);

        $format = $fn->getParam(0);
        $timestamp = $fn->getParam(1);
        $strMap = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $yearBase = $i32->constInt(1900, false);

        $fmtLen = $context->builder->load($context->builder->structGep($format, $strMap['length']));
        $fmtChars = $context->builder->structGep($format, $strMap['value']);
        $lenOk = $context->builder->icmp(Builder::INT_EQ, $fmtLen, $one);
        $badFmtBb = $fn->appendBasicBlock('idate_bad_fmt');
        $bodyBb = $fn->appendBasicBlock('idate_body');
        $context->builder->branchIf($lenOk, $bodyBb, $badFmtBb);

        $context->builder->positionAtEnd($badFmtBb);
        self::emitWarning($context, 'idate format is one char');
        $context->builder->returnValue($i64->constInt(self::ERR_FORMAT, true));

        $context->builder->positionAtEnd($bodyBb);
        $ch = $context->builder->load($context->builder->gep($fmtChars, $zero));
        $chI32 = $context->builder->zExt($ch, $i32);

        $i64p = $context->getTypeFromString('int64*');
        $tsSlot = $context->builder->alloca($i64, 1, 'idate_ts');
        $context->builder->store($timestamp, $tsSlot);
        $tsPtr = $context->builder->pointerCast($tsSlot, $i64p);
        $tmPtr = $context->builder->call($context->lookupFunction('localtime'), $tsPtr);

        $tmYear = self::loadTmField($context, $tmPtr, self::TM_YEAR);
        $tmMon = self::loadTmField($context, $tmPtr, self::TM_MON);
        $tmMday = self::loadTmField($context, $tmPtr, self::TM_MDAY);
        $tmHour = self::loadTmField($context, $tmPtr, self::TM_HOUR);
        $tmMin = self::loadTmField($context, $tmPtr, self::TM_MIN);
        $tmSec = self::loadTmField($context, $tmPtr, self::TM_SEC);
        $tmWday = self::loadTmField($context, $tmPtr, self::TM_WDAY);
        $tmYday = self::loadTmField($context, $tmPtr, self::TM_YDAY);
        $tmIsdst = self::loadTmField($context, $tmPtr, self::TM_ISDST);

        $year = $context->builder->add($context->builder->zExt($tmYear, $i64), $context->builder->zExt($yearBase, $i64));
        $month = $context->builder->addNoSignedWrap($context->builder->zExt($tmMon, $i64), $one);
        $day = $context->builder->zExt($tmMday, $i64);
        $hour = $context->builder->zExt($tmHour, $i64);
        $minute = $context->builder->zExt($tmMin, $i64);
        $second = $context->builder->zExt($tmSec, $i64);
        $wday = $context->builder->zExt($tmWday, $i64);
        $yday = $context->builder->zExt($tmYday, $i64);
        $y2 = $context->builder->signedRem($year, $i64->constInt(100, false));
        $hour12 = self::hour12($context, $hour, $i64);
        $dstFlag = self::dstFlag($context, $tmIsdst, $i64);
        $isoDow = self::isoDayOfWeek($context, $wday, $i64);

        $cases = [
            'd' => $day,
            'j' => $day,
            'H' => $hour,
            'h' => $hour12,
            'g' => $hour12,
            'i' => $minute,
            's' => $second,
            'm' => $month,
            'n' => $month,
            'Y' => $year,
            'y' => $y2,
            'w' => $wday,
            'z' => $yday,
            'U' => $timestamp,
            'I' => $dstFlag,
            'N' => $isoDow,
        ];

        $badTokenBb = $fn->appendBasicBlock('idate_bad_token');
        $fallthrough = $bodyBb;
        $chars = array_keys($cases);
        foreach ($chars as $idx => $char) {
            $matchBb = $fn->appendBasicBlock('idate_match_'.$char);
            $nextBb = isset($chars[$idx + 1])
                ? $fn->appendBasicBlock('idate_fall_'.$char)
                : $badTokenBb;
            $context->builder->positionAtEnd($fallthrough);
            $eq = $context->builder->icmp(Builder::INT_EQ, $chI32, $i32->constInt(\ord($char), false));
            $context->builder->branchIf($eq, $matchBb, $nextBb);

            $context->builder->positionAtEnd($matchBb);
            $context->builder->returnValue($cases[$char]);

            $fallthrough = $nextBb;
        }

        $context->builder->positionAtEnd($badTokenBb);
        self::emitWarning($context, 'Unrecognized date format token');
        $context->builder->returnValue($i64->constInt(self::ERR_TOKEN, true));
        $context->builder->clearInsertionPosition();
    }

    private static function hour12(Context $context, Value $hour, $i64): Value
    {
        $twelve = $i64->constInt(12, false);
        $mod = $context->builder->signedRem($hour, $twelve);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $mod, $i64->constInt(0, false));

        return $context->builder->select($isZero, $twelve, $mod);
    }

    private static function dstFlag(Context $context, Value $tmIsdst, $i64): Value
    {
        $isPositive = $context->builder->icmp(
            Builder::INT_SGT,
            $context->builder->zExt($tmIsdst, $i64),
            $i64->constInt(0, false)
        );

        return $context->builder->select($isPositive, $i64->constInt(1, false), $i64->constInt(0, false));
    }

    private static function isoDayOfWeek(Context $context, Value $wday, $i64): Value
    {
        $isSun = $context->builder->icmp(Builder::INT_EQ, $wday, $i64->constInt(0, false));

        return $context->builder->select($isSun, $i64->constInt(7, false), $wday);
    }

    private static function loadTmField(Context $context, Value $tmPtr, int $offset): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i32p = $context->getTypeFromString('int32*');
        $tmFields = $context->builder->pointerCast($tmPtr, $i32p);

        return $context->builder->load(
            $context->builder->gep($tmFields, $i32->constInt((int) ($offset / 4), false))
        );
    }

    private static function emitWarning(Context $context, string $message): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $msg = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $context->builder->call($context->lookupFunction('strlen'), $msg);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msg,
            $msgLen,
            $i32->constInt(2, false),
            $context->builder->pointerCast($context->constantFromString(''), $i8p),
            $i32->constInt(0, false)
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__compiler_idate');
        if (null === $fn) {
            throw new \LogicException('__compiler_idate missing after StringIdate LLVM implement');
        }
        $context->registerFunction('__compiler_idate', $fn);
    }
}
