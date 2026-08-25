<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT StringUnserialize restore for Zend date extension wires (#34599 / peer #34594).
 *
 * php-src: ext/date/php_date.c — php_date_unserialize
 */
final class DateUnserializeJitHelper
{
    private const HELPER_PATH = '/ext/standard/UnserializeDateWireNestedJitHelper.php';

    private const PARSE_DT = 'PHPCompiler\\ext\\standard\\UnserializeDateWireNestedJitHelper::parseDateTimeLike';

    private const PARSE_TZ = 'PHPCompiler\\ext\\standard\\UnserializeDateWireNestedJitHelper::parseDateTimeZone';

    private const OUT_TS = 'PHPCompiler\\ext\\standard\\UnserializeDateWireNestedJitHelper::outTimestamp';

    private const OUT_US = 'PHPCompiler\\ext\\standard\\UnserializeDateWireNestedJitHelper::outMicrosecond';

    private const OUT_TZ_OFF = 'PHPCompiler\\ext\\standard\\UnserializeDateWireNestedJitHelper::outTzOff';

    private const OUT_TZ_LEN = 'PHPCompiler\\ext\\standard\\UnserializeDateWireNestedJitHelper::outTzLen';

    private const OUT_Y = 'PHPCompiler\\ext\\standard\\UnserializeDateWireNestedJitHelper::outY';

    private const OUT_M = 'PHPCompiler\\ext\\standard\\UnserializeDateWireNestedJitHelper::outM';

    private const OUT_D = 'PHPCompiler\\ext\\standard\\UnserializeDateWireNestedJitHelper::outD';

    private const OUT_H = 'PHPCompiler\\ext\\standard\\UnserializeDateWireNestedJitHelper::outH';

    private const OUT_I = 'PHPCompiler\\ext\\standard\\UnserializeDateWireNestedJitHelper::outI';

    private const OUT_S = 'PHPCompiler\\ext\\standard\\UnserializeDateWireNestedJitHelper::outS';

    public static function compileDateTimeLikeRestore(
        Context $context,
        Value $obj,
        Value $payloadString,
        string $className
    ): void {
        \PHPCompiler\JIT\Builtin\StringUnserialize::ensureLinked($context);
        $payloadOwned = self::nestedJitOwnedString($context, $payloadString);
        self::ensureHelpers($context, [
            self::PARSE_DT,
            self::OUT_TS,
            self::OUT_US,
            self::OUT_TZ_OFF,
            self::OUT_TZ_LEN,
            self::OUT_Y,
            self::OUT_M,
            self::OUT_D,
            self::OUT_H,
            self::OUT_I,
            self::OUT_S,
        ], '#34599');
        $parseFn = JitVmHelperLink::lookupCompiled($context, self::PARSE_DT, '#34599');
        $outTsFn = JitVmHelperLink::lookupCompiled($context, self::OUT_TS, '#34599');
        $outUsFn = JitVmHelperLink::lookupCompiled($context, self::OUT_US, '#34599');
        $outTzOffFn = JitVmHelperLink::lookupCompiled($context, self::OUT_TZ_OFF, '#34599');
        $outTzLenFn = JitVmHelperLink::lookupCompiled($context, self::OUT_TZ_LEN, '#34599');
        $outYFn = JitVmHelperLink::lookupCompiled($context, self::OUT_Y, '#34599');
        $outMFn = JitVmHelperLink::lookupCompiled($context, self::OUT_M, '#34599');
        $outDFn = JitVmHelperLink::lookupCompiled($context, self::OUT_D, '#34599');
        $outHFn = JitVmHelperLink::lookupCompiled($context, self::OUT_H, '#34599');
        $outIFn = JitVmHelperLink::lookupCompiled($context, self::OUT_I, '#34599');
        $outSFn = JitVmHelperLink::lookupCompiled($context, self::OUT_S, '#34599');
        $i64 = $context->getTypeFromString('int64');
        $okRaw = $context->builder->call(
            $parseFn,
            JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $payloadOwned,
                $parseFn->getParam(0)->typeOf()
            )
        );
        $ok = JitNestedHelperCoerce::coerceBridgeResult($context, $okRaw, $i64);
        $parent = BasicBlockHelper::parentFunction($context);
        $bbApply = $parent->appendBasicBlock('date_unser_dt_apply');
        $bbDone = $parent->appendBasicBlock('date_unser_dt_done');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $ok, $i64->constInt(0, false)),
            $bbApply,
            $bbDone
        );
        $context->builder->positionAtEnd($bbApply);
        // outTimestamp holds TZ offset seconds for this path; civil→unix via LLVM (#34599).
        $tzOffset = JitNestedHelperCoerce::coerceBridgeResult(
            $context,
            $context->builder->call($outTsFn),
            $i64
        );
        $us = JitNestedHelperCoerce::coerceBridgeResult(
            $context,
            $context->builder->call($outUsFn),
            $i64
        );
        $y = JitNestedHelperCoerce::coerceBridgeResult($context, $context->builder->call($outYFn), $i64);
        $m = JitNestedHelperCoerce::coerceBridgeResult($context, $context->builder->call($outMFn), $i64);
        $d = JitNestedHelperCoerce::coerceBridgeResult($context, $context->builder->call($outDFn), $i64);
        $h = JitNestedHelperCoerce::coerceBridgeResult($context, $context->builder->call($outHFn), $i64);
        $i = JitNestedHelperCoerce::coerceBridgeResult($context, $context->builder->call($outIFn), $i64);
        $s = JitNestedHelperCoerce::coerceBridgeResult($context, $context->builder->call($outSFn), $i64);
        $civilTs = \PHPCompiler\ext\standard\JitGetdate::timestampFromCivilPublic(
            $context,
            $y,
            $m,
            $d,
            $h,
            $i,
            $s
        );
        $ts = $context->builder->sub($civilTs, $tzOffset);
        $tzOff = JitNestedHelperCoerce::coerceBridgeResult(
            $context,
            $context->builder->call($outTzOffFn),
            $i64
        );
        $tzLen = JitNestedHelperCoerce::coerceBridgeResult(
            $context,
            $context->builder->call($outTzLenFn),
            $i64
        );
        self::writeLongProp($context, $obj, $className, DateTimeSupport::TS_PROPERTY, $ts);
        self::writeLongProp($context, $obj, $className, DateTimeSupport::MICROSECOND_PROPERTY, $us);
        self::writePayloadSliceProp(
            $context,
            $obj,
            $className,
            DateTimeSupport::TZ_PROPERTY,
            $payloadOwned,
            $tzOff,
            $tzLen
        );
        $context->builder->branch($bbDone);
        $context->builder->positionAtEnd($bbDone);
    }

    public static function compileDateTimeZoneRestore(
        Context $context,
        Value $obj,
        Value $payloadString
    ): void {
        \PHPCompiler\JIT\Builtin\StringUnserialize::ensureLinked($context);
        $payloadOwned = self::nestedJitOwnedString($context, $payloadString);
        self::ensureHelpers($context, [
            self::PARSE_TZ,
            self::OUT_TZ_OFF,
            self::OUT_TZ_LEN,
        ], '#34599');
        $parseFn = JitVmHelperLink::lookupCompiled($context, self::PARSE_TZ, '#34599');
        $outTzOffFn = JitVmHelperLink::lookupCompiled($context, self::OUT_TZ_OFF, '#34599');
        $outTzLenFn = JitVmHelperLink::lookupCompiled($context, self::OUT_TZ_LEN, '#34599');
        $i64 = $context->getTypeFromString('int64');
        $okRaw = $context->builder->call(
            $parseFn,
            JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $payloadOwned,
                $parseFn->getParam(0)->typeOf()
            )
        );
        $ok = JitNestedHelperCoerce::coerceBridgeResult($context, $okRaw, $i64);
        $parent = BasicBlockHelper::parentFunction($context);
        $bbApply = $parent->appendBasicBlock('date_unser_tz_apply');
        $bbDone = $parent->appendBasicBlock('date_unser_tz_done');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $ok, $i64->constInt(0, false)),
            $bbApply,
            $bbDone
        );
        $context->builder->positionAtEnd($bbApply);
        $tzOff = JitNestedHelperCoerce::coerceBridgeResult(
            $context,
            $context->builder->call($outTzOffFn),
            $i64
        );
        $tzLen = JitNestedHelperCoerce::coerceBridgeResult(
            $context,
            $context->builder->call($outTzLenFn),
            $i64
        );
        self::writePayloadSliceProp(
            $context,
            $obj,
            'DateTimeZone',
            DateTimeSupport::TZ_NAME_PROPERTY,
            $payloadOwned,
            $tzOff,
            $tzLen
        );
        $context->builder->branch($bbDone);
        $context->builder->positionAtEnd($bbDone);
    }

    /** @param list<string> $logicals */
    private static function ensureHelpers(Context $context, array $logicals, string $issue): void
    {
        foreach ($logicals as $logical) {
            $saved = BasicBlockHelper::tryGetInsertBlock($context);
            JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, [$logical], $issue);
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        }
    }

    private static function writePayloadSliceProp(
        Context $context,
        Value $obj,
        string $className,
        string $propName,
        Value $payloadOwned,
        Value $off,
        Value $len
    ): void {
        $map = $context->structFieldMap['__string__'];
        $i8p = $context->getTypeFromString('int8*');
        $base = $context->builder->pointerCast(
            $context->builder->structGep($payloadOwned, $map['value']),
            $i8p
        );
        $src = $context->builder->gep($base, $off);
        $slice = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $src
        );
        $objectType = $context->type->object;
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, $className, $propName),
            new JITVariable(
                $context,
                JITVariable::TYPE_STRING,
                JITVariable::KIND_VALUE,
                $slice
            ),
            JITVariable::TYPE_STRING
        );
    }

    private static function writeLongProp(
        Context $context,
        Value $obj,
        string $className,
        string $propName,
        Value $value
    ): void {
        $objectType = $context->type->object;
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, $className, $propName),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $value
            ),
            JITVariable::TYPE_NATIVE_LONG
        );
    }

    private static function nestedJitOwnedString(Context $context, Value $payload): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $separated = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $payload
        );
        $slot = BasicBlockHelper::entryAlloca($context, $strPtr);
        $context->builder->store($separated, $slot);
        $loaded = $context->builder->load($slot);
        $map = $context->structFieldMap['__string__'];
        $i8p = $context->getTypeFromString('int8*');
        $len = $context->builder->call($context->lookupFunction('__string__strlen'), $loaded);
        $src = $context->builder->pointerCast(
            $context->builder->structGep($loaded, $map['value']),
            $i8p
        );
        $copy = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $src
        );
        $context->refcount->disableRefcount($copy);

        return $copy;
    }
}
