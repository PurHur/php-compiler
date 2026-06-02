<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Block;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringHrtime;
use PHPCompiler\JIT\Builtin\StringMicrotime;
use PHPCompiler\JIT\Context;
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
        $i64 = $context->getTypeFromString('int64');
        $raw = $context->builder->call($context->lookupFunction('getpid'));

        return $raw->typeOf() === $i64
            ? $raw
            : $context->builder->zExt($raw, $i64);
    }

    public static function getmygrgid(Context $context): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $raw = $context->builder->call($context->lookupFunction('getgid'));

        return $raw->typeOf() === $i64
            ? $raw
            : $context->builder->zExt($raw, $i64);
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
            $context->lookupFunction('__value__writeLong'),
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
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('date()/gmdate() require one or two arguments');
        }
        $format = self::jitStringArg($context, $args[0]);
        $i64 = $context->getTypeFromString('int64');
        $timestamp = $argc >= 2
            ? self::jitTimestampArg($context, $args[1])
            : self::time($context);
        $gmtI8 = $context->getTypeFromString('int8')->constInt($gmt ? 1 : 0, false);

        return $context->builder->call(
            $context->lookupFunction('__compiler_format_datetime'),
            $format,
            $timestamp,
            $gmtI8
        );
    }

    private static function jitTimestampArg(Context $context, JITVariable $arg): Value
    {
        $i64 = $context->getTypeFromString('int64');
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $arg->value
            );
        }

        throw new \LogicException('date() timestamp must be an integer or null in this compiler build');
    }

    private static function jitStringArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                $arg->value
            );
        }

        throw new \LogicException('date() format must be a string in this compiler build');
    }
}
