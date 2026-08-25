<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Thin AOT: restore DateTime / DateTimeImmutable from runtime unserialize strings (#34594).
 *
 * Split NestedJIT TUs + {@see JitNestedHelperCoerce::coerceBridgeResult} for int offsets
 * (peer ArrayObject findOff). php-src: ext/date/php_date.c — php_date_unserialize
 */
final class DateTimeUnserializeJitHelper
{
    private const HEADER_PATH = '/ext/standard/UnserializeDateTimeHeaderNestedJitHelper.php';

    private const SKIP_PATH = '/ext/standard/UnserializeDateTimeSkipKeyNestedJitHelper.php';

    private const CIVIL_PATH = '/ext/standard/UnserializeDateTimeCivilNestedJitHelper.php';

    private const TZ_PATH = '/ext/standard/UnserializeDateTimeExtractNestedJitHelper.php';

    private const AFTER_BRACE = 'PHPCompiler\\ext\\standard\\UnserializeDateTimeHeaderNestedJitHelper::afterBrace';

    private const VALUE_START = 'PHPCompiler\\ext\\standard\\UnserializeDateTimeSkipKeyNestedJitHelper::valueStart';

    private const EPOCH_AT = 'PHPCompiler\\ext\\standard\\UnserializeDateTimeCivilNestedJitHelper::utcEpochAt';

    private const US_AT = 'PHPCompiler\\ext\\standard\\UnserializeDateTimeCivilNestedJitHelper::microsecondAt';

    private const TZ = 'PHPCompiler\\ext\\standard\\UnserializeDateTimeExtractNestedJitHelper::extractTimezone';

    public static function compileUnserializeRestore(
        Context $context,
        Value $obj,
        Value $payloadString,
        string $className
    ): void {
        \PHPCompiler\JIT\Builtin\StringUnserialize::ensureLinked($context);
        foreach (
            [
                [self::HEADER_PATH, [self::AFTER_BRACE]],
                [self::SKIP_PATH, [self::VALUE_START]],
                [self::CIVIL_PATH, [self::EPOCH_AT, self::US_AT]],
                [self::TZ_PATH, [self::TZ]],
            ] as [$path, $logicals]
        ) {
            $saved = BasicBlockHelper::tryGetInsertBlock($context);
            JitVmHelperLink::ensureCompiled($context, $path, $logicals, '#34594');
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        }

        $afterFn = JitVmHelperLink::lookupCompiled($context, self::AFTER_BRACE, '#34594');
        $valueFn = JitVmHelperLink::lookupCompiled($context, self::VALUE_START, '#34594');
        $epochFn = JitVmHelperLink::lookupCompiled($context, self::EPOCH_AT, '#34594');
        $usFn = JitVmHelperLink::lookupCompiled($context, self::US_AT, '#34594');
        $tzFn = JitVmHelperLink::lookupCompiled($context, self::TZ, '#34594');

        $payloadOwned = self::nestedJitOwnedString($context, $payloadString);
        $i64 = $context->getTypeFromString('int64');

        $braceRaw = $context->builder->call(
            $afterFn,
            JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $payloadOwned,
                $afterFn->getParam(0)->typeOf()
            )
        );
        $bracePos = JitNestedHelperCoerce::coerceBridgeResult($context, $braceRaw, $i64);

        $valueRaw = $context->builder->call(
            $valueFn,
            JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $payloadOwned,
                $valueFn->getParam(0)->typeOf()
            ),
            JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $bracePos,
                $valueFn->getParam(1)->typeOf()
            )
        );
        $valuePos = JitNestedHelperCoerce::coerceBridgeResult($context, $valueRaw, $i64);

        $tsRaw = $context->builder->call(
            $epochFn,
            JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $payloadOwned,
                $epochFn->getParam(0)->typeOf()
            ),
            JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $valuePos,
                $epochFn->getParam(1)->typeOf()
            )
        );
        $ts = JitNestedHelperCoerce::coerceBridgeResult($context, $tsRaw, $i64);

        $usRaw = $context->builder->call(
            $usFn,
            JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $payloadOwned,
                $usFn->getParam(0)->typeOf()
            ),
            JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $valuePos,
                $usFn->getParam(1)->typeOf()
            )
        );
        $us = JitNestedHelperCoerce::coerceBridgeResult($context, $usRaw, $i64);

        $tzRaw = $context->builder->call(
            $tzFn,
            JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $payloadOwned,
                $tzFn->getParam(0)->typeOf()
            )
        );
        $tzStr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $tzRaw);

        $objectType = $context->type->object;
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, $className, DateTimeSupport::TS_PROPERTY),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $ts
            ),
            JITVariable::TYPE_NATIVE_LONG
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, $className, DateTimeSupport::MICROSECOND_PROPERTY),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $us
            ),
            JITVariable::TYPE_NATIVE_LONG
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, $className, DateTimeSupport::TZ_PROPERTY),
            new JITVariable(
                $context,
                JITVariable::TYPE_STRING,
                JITVariable::KIND_VALUE,
                $tzStr
            ),
            JITVariable::TYPE_STRING
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
