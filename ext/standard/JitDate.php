<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Block;
use PHPCompiler\CompilerVersion;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DefaultTimezoneRuntime;
use PHPCompiler\JIT\Builtin\ProcessIdentityJit;
use PHPCompiler\JIT\Builtin\StringDateTime;
use PHPCompiler\JIT\Builtin\StringHrtime;
use PHPCompiler\JIT\Builtin\StringMicrotime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\ScriptMagic;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\OpCode;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitDate
{
    public static function time(Context $context): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        $timeT = $context->getTypeFromString('int64');
        $raw = $context->builder->call(
            $context->lookupFunction('time'),
            $i8p->constNull()
        );

        return $raw->typeOf() === $timeT
            ? $raw
            : $context->builder->zExt($raw, $timeT);
    }

    public static function getmypid(Context $context): Value
    {
        return ProcessIdentityJit::getmypid($context);
    }

    public static function getmygrgid(Context $context): Value
    {
        return ProcessIdentityJit::getmygid($context);
    }

    public static function getmyuid(Context $context): Value
    {
        return ProcessIdentityJit::getmyuid($context);
    }

    public static function getmygid(Context $context): Value
    {
        return ProcessIdentityJit::getmygid($context);
    }

    /** date_default_timezone_get() — process default timezone id (#3292 phase 2). */
    public static function defaultTimezoneGet(Context $context): Value
    {
        DefaultTimezoneRuntime::ensureLinked($context);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_default_timezone_get'),
            $ptr
        );

        return $ptr;
    }

    /** date_default_timezone_set() — validate and store default timezone (#3292 phase 2). */
    public static function defaultTimezoneSet(Context $context, JITVariable $timezoneId): Value
    {
        DefaultTimezoneRuntime::ensureLinked($context);

        // php_date.stub.php — null DEP+coerce on 8.4 forward profile (#21369, re-#20959)
        $tz = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $timezoneId,
            'date_default_timezone_set',
            0,
            'timezoneId'
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_default_timezone_set'),
            $tz,
            $ptr
        );

        return $ptr;
    }

    /** timezone_version_get() — tzdata version baked at JIT link from VmDate (#6832, #8032). */
    public static function timezone_version_get(Context $context): Value
    {
        $version = VmDate::timezone_version_get();
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $owned = $context->builder->load($context->constantStringFromString($version));
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );

        return $ptr;
    }

    public static function getmyinode(Context $context): Value
    {
        $block = $context->jitEnclosingBlock;
        if (!$block instanceof Block) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $i1 = $context->getTypeFromString('int1');
            JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

            return $ptr;
        }

        $path = ScriptMagic::stringForBlock($block, OpCode::SCRIPT_MAGIC_FILE);
        if ('' === $path) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $i1 = $context->getTypeFromString('int1');
            JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

            return $ptr;
        }

        $pathStr = $context->builder->load($context->constantStringFromString($path));

        return JitStat::pathFileInodeBoxed($context, $pathStr);
    }

    public static function getlastmod(Context $context): Value
    {
        $block = $context->jitEnclosingBlock;
        if (!$block instanceof Block) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $i1 = $context->getTypeFromString('int1');
            JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

            return $ptr;
        }

        $path = ScriptMagic::stringForBlock($block, OpCode::SCRIPT_MAGIC_FILE);
        if ('' === $path) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $i1 = $context->getTypeFromString('int1');
            JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

            return $ptr;
        }

        $pathStr = $context->builder->load($context->constantStringFromString($path));

        return JitStat::pathFileMtimeBoxed($context, $pathStr);
    }

    public static function microtime(Context $context, Value $asFloat): Value
    {
        StringMicrotime::ensureLinked($context);

        $slot = JitValueBox::alloc($context);
        $slotPtr = JitValueBox::pointer($context, $slot);
        $isFloat = $context->builder->icmp(
            Builder::INT_NE,
            $asFloat,
            $context->constantFromBool(false)
        );
        $floatBb = BasicBlockHelper::append($context, 'microtime_float');
        $stringBb = BasicBlockHelper::append($context, 'microtime_string');
        $mergeBb = BasicBlockHelper::append($context, 'microtime_merge');
        $context->builder->branchIf($isFloat, $floatBb, $stringBb);

        $context->builder->positionAtEnd($floatBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $slotPtr,
            $context->builder->call($context->lookupFunction('__compiler_microtime_float'))
        );
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($stringBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $slotPtr,
            $context->builder->call($context->lookupFunction('__compiler_microtime_string'))
        );
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);

        return $slotPtr;
    }

    public static function hrtime(Context $context, Value $asNumber): Value
    {
        StringHrtime::ensureLinked($context);

        $slot = JitValueBox::alloc($context);
        $slotPtr = JitValueBox::pointer($context, $slot);
        $isNumber = $context->builder->icmp(
            Builder::INT_NE,
            $asNumber,
            $context->constantFromBool(false)
        );
        $numberBb = BasicBlockHelper::append($context, 'hrtime_number');
        $pairBb = BasicBlockHelper::append($context, 'hrtime_pair');
        $mergeBb = BasicBlockHelper::append($context, 'hrtime_merge');
        $context->builder->branchIf($isNumber, $numberBb, $pairBb);

        $context->builder->positionAtEnd($numberBb);
        if (CompilerVersion::supportsHrtimeAsNumberFloat()) {
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $context->builder->call($context->lookupFunction('__compiler_hrtime_ns'))
            );
        } else {
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                $slotPtr,
                $context->builder->call($context->lookupFunction('__compiler_hrtime_ns'))
            );
        }
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($pairBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $slotPtr,
            $context->builder->call($context->lookupFunction('__compiler_hrtime_pair'))
        );
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);

        return $slotPtr;
    }

    public static function formatDate(Context $context, bool $gmt, JITVariable ...$args): Value
    {
        $argc = \count($args);
        $function = $gmt ? 'gmdate' : 'date';
        if ($argc < 1) {
            throw new \ArgumentCountError("{$function}() expects at least 1 argument, 0 given");
        }
        if ($argc > 2) {
            throw new \ArgumentCountError("{$function}() expects at most 2 arguments, {$argc} given");
        }
        $timestamp = $argc >= 2
            ? JitDateTimestampArg::lowerNullable(
                $context,
                $args[1],
                $gmt ? 'gmdate' : 'date',
                2,
                'timestamp',
                self::time($context)
            )
            : self::time($context);

        // Thin AOT: NestedJIT FormatDatetime segfaults; Y-m-d via UTC civil IR (#27091).
        // Matches Zend when default timezone is UTC (CI / docker image default).
        $fmtLit = JitStringBuiltinArg::compileTimeLiteral($args[0]) ?? $args[0]->compileTimeString;
        if ('Y-m-d' === $fmtLit && ($gmt || self::defaultTimezoneIsUtc())) {
            return self::formatYmdCivil($context, $timestamp);
        }

        // Soft-null on 8.4 — Zend deprecate+coerce (#21208, reverts #19651 TypeError)
        $format = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], $function, 0, 'format');
        $gmtI8 = $context->getTypeFromString('int8')->constInt($gmt ? 1 : 0, false);

        StringDateTime::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_format_datetime'),
            $format,
            $timestamp,
            $gmtI8
        );
    }

    private static function defaultTimezoneIsUtc(): bool
    {
        $tz = \date_default_timezone_get();

        return 'UTC' === $tz || 'Etc/UTC' === $tz || 'Z' === $tz || 'GMT' === $tz;
    }

    /** Format timestamp as Y-m-d via {@see JitGetdate::civilPartsPublic} + snprintf (#27091). */
    private static function formatYmdCivil(Context $context, Value $timestamp): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'date_ymd_civil');
        $parts = JitGetdate::civilPartsPublic($context, $timestamp);
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');
        $buf = $context->builder->alloca($i8, 16, 'ymd_buf');
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $fmt = $context->builder->pointerCast($context->constantFromString('%04lld-%02lld-%02lld'), $charPtr);
        if (null === $context->module->getNamedFunction('snprintf')) {
            $context->module->addFunction(
                'snprintf',
                $context->context->functionType(
                    $context->getTypeFromString('int32'),
                    true,
                    $charPtr,
                    $sizeT,
                    $charPtr
                )
            );
        }
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $sizeT->constInt(16, false),
            $fmt,
            $parts['year'],
            $parts['month'],
            $parts['day']
        );
        $len = $context->builder->sext($written, $i64);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $bufChar
        );
    }

    public static function formatStrftime(Context $context, bool $gmt, JITVariable ...$args): Value
    {
        $argc = \count($args);
        $function = $gmt ? 'gmstrftime' : 'strftime';
        if ($argc < 1) {
            throw new \ArgumentCountError("{$function}() expects at least 1 argument, 0 given");
        }
        if ($argc > 2) {
            throw new \ArgumentCountError("{$function}() expects at most 2 arguments, {$argc} given");
        }
        VmEngineBuiltinDeprecation::emitJitFunction($context, $function);
        // Soft-null $format → DEP + false (Zend 8.4.23; #21582, reverts #20227 TypeError).
        // Keep false (not '') for #18945 — do not lower through Z_PARAM_STR → php_strftime("").
        // Compile-time null folds to native bool like checkdate AOT (#21594) — value-box false
        // segfaults under AOT assign/var_export.
        // strict_types still TypeError via lowerZparamStr below.
        if (
            (JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant)
            && !$context->callerStrictTypes
        ) {
            JitStringBuiltinArg::emitNullStringParamDeprecation($context, $function, 0, 'format');

            return $context->constantFromBool(false);
        }
        $format = JitStringBuiltinArg::lowerZparamStr($context, $args[0], $function, 0, 'format');
        $timestamp = $argc >= 2
            ? JitDateTimestampArg::lowerNullable(
                $context,
                $args[1],
                $gmt ? 'gmstrftime' : 'strftime',
                2,
                'timestamp',
                self::time($context)
            )
            : self::time($context);
        $gmtI8 = $context->getTypeFromString('int8')->constInt($gmt ? 1 : 0, false);

        return $context->builder->call(
            $context->lookupFunction('__compiler_strftime'),
            $format,
            $timestamp,
            $gmtI8
        );
    }

}
