<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\InetRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for inet_* / ip2long / long2ip (JIT/AOT, issue #3225). */
final class JitInet
{
    private static int $blockSerial = 0;

    public static function ip2long(Context $context, Value $ip): Value
    {
        InetRuntime::ensureLinked($context);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_ip2long'),
            $ptr,
            $ip
        );

        return $ptr;
    }

    public static function long2ip(Context $context, Value $addr): Value
    {
        InetRuntime::ensureLinked($context);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_long2ip'),
            $ptr,
            $addr
        );

        return $ptr;
    }

    public static function inetPton(Context $context, Value $address): Value
    {
        InetRuntime::ensureLinked($context);
        $packed = $context->builder->call(
            $context->lookupFunction('__compiler_inet_pton'),
            $address
        );

        return self::boxedStringOrFalse($context, $packed);
    }

    public static function inetNtop(Context $context, Value $inAddr): Value
    {
        InetRuntime::ensureLinked($context);
        $text = $context->builder->call(
            $context->lookupFunction('__compiler_inet_ntop'),
            $inAddr
        );

        return self::boxedStringOrFalse($context, $text);
    }

    private static function boxedStringOrFalse(Context $context, Value $str): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $str, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'inet_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'inet_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'inet_done_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $str
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
