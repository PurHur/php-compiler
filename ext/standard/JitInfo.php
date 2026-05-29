<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringInfo;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for phpversion/php_uname/php_sapi_name via phpc_info.c (#3174). */
final class JitInfo
{
    public static function phpversion(Context $context, ?JITVariable $extension): Value
    {
        StringInfo::ensureLinked($context);
        $strPtr = $context->getTypeFromString('__string__*');
        $extArg = $strPtr->constNull();
        if (null !== $extension) {
            $extArg = JitStringArg::lower($context, $extension, 'phpversion() extension');
        }
        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_phpversion'),
            $extArg
        );

        return self::stringOrFalse($context, $raw, 'phpversion');
    }

    public static function php_sapi_name(Context $context): Value
    {
        StringInfo::ensureLinked($context);
        $raw = $context->builder->call($context->lookupFunction('__compiler_php_sapi_name'));
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $raw);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }

    public static function php_uname(Context $context, ?Value $mode): Value
    {
        StringInfo::ensureLinked($context);
        $strPtr = $context->getTypeFromString('__string__*');
        if (null === $mode) {
            $mode = $context->constantStringFromString('a');
        }
        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_php_uname'),
            $mode
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $raw);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }

    private static function stringOrFalse(Context $context, Value $raw, string $label): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $raw, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, $label.'_fail');
        $okBlock = BasicBlockHelper::append($context, $label.'_ok');
        $doneBlock = BasicBlockHelper::append($context, $label.'_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $raw);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
