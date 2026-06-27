<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Block;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DefaultTimezoneRuntime;
use PHPCompiler\JIT\Builtin\ProcessIdentityJit;
use PHPCompiler\JIT\Builtin\StringHrtime;
use PHPCompiler\JIT\Builtin\StringMicrotime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringArg;
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

        $tz = JitStringBuiltinArg::lower(
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
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $slotPtr,
            $context->builder->call($context->lookupFunction('__compiler_hrtime_ns'))
        );
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
        JitInternalStrictArg::rejectNullString($context, $args[0], $function, 'format', 1);
        JitInternalStrictArg::requireString($context, $args[0], $function, 'format', 1);
        $format = JitStringArg::lower($context, $args[0], "{$function}() argument #1 ($format)");
        $i64 = $context->getTypeFromString('int64');
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
        $gmtI8 = $context->getTypeFromString('int8')->constInt($gmt ? 1 : 0, false);

        return $context->builder->call(
            $context->lookupFunction('__compiler_format_datetime'),
            $format,
            $timestamp,
            $gmtI8
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
        JitInternalStrictArg::requireString($context, $args[0], $function, 'format', 1);
        $format = JitStringArg::lower($context, $args[0], "{$function}() argument #1 (format)");
        $i64 = $context->getTypeFromString('int64');
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
