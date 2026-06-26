<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringZlib;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM helpers for gz*() — libz via ZlibRuntime → ZlibJitHelper (issues #3194, #6791, #9879). */
final class JitZlib
{
    private static int $blockSerial = 0;

    public static function compress(Context $context, Value $data, Value $level, Value $encoding): Value
    {
        StringZlib::ensureLinked($context);

        return self::stringOrFalse($context, $context->builder->call(
            $context->lookupFunction('__compiler_gzcompress'),
            $data,
            $level,
            $encoding
        ));
    }

    public static function uncompress(Context $context, Value $data, Value $maxLength): Value
    {
        StringZlib::ensureLinked($context);

        return self::stringOrFalse($context, $context->builder->call(
            $context->lookupFunction('__compiler_gzuncompress'),
            $data,
            $maxLength
        ));
    }

    public static function deflate(Context $context, Value $data, Value $level, Value $encoding): Value
    {
        StringZlib::ensureLinked($context);

        return self::stringOrFalse($context, $context->builder->call(
            $context->lookupFunction('__compiler_gzdeflate'),
            $data,
            $level,
            $encoding
        ));
    }

    public static function inflate(Context $context, Value $data, Value $maxLength): Value
    {
        StringZlib::ensureLinked($context);

        return self::stringOrFalse($context, $context->builder->call(
            $context->lookupFunction('__compiler_gzinflate'),
            $data,
            $maxLength
        ));
    }

    public static function encode(Context $context, Value $data, Value $level, Value $encoding): Value
    {
        StringZlib::ensureLinked($context);

        return self::stringOrFalse($context, $context->builder->call(
            $context->lookupFunction('__compiler_gzencode'),
            $data,
            $level,
            $encoding
        ));
    }

    public static function decode(Context $context, Value $data, Value $maxLength): Value
    {
        StringZlib::ensureLinked($context);

        return self::stringOrFalse($context, $context->builder->call(
            $context->lookupFunction('__compiler_gzdecode'),
            $data,
            $maxLength
        ));
    }

    public static function zlibEncode(Context $context, Value $data, Value $encoding, Value $level): Value
    {
        StringZlib::ensureLinked($context);

        return self::stringOrFalse($context, $context->builder->call(
            $context->lookupFunction('__compiler_zlib_encode'),
            $data,
            $encoding,
            $level
        ));
    }

    public static function zlibDecode(Context $context, Value $data, Value $maxLength): Value
    {
        StringZlib::ensureLinked($context);

        return self::stringOrFalse($context, $context->builder->call(
            $context->lookupFunction('__compiler_zlib_decode'),
            $data,
            $maxLength
        ));
    }

    public static function getCodingType(Context $context): Value
    {
        StringZlib::ensureLinked($context);

        return self::stringOrFalse($context, $context->builder->call(
            $context->lookupFunction('__compiler_zlib_get_coding_type')
        ));
    }

    private static function stringOrFalse(Context $context, Value $result): Value
    {
        $id = (string) (++self::$blockSerial);
        $strPtr = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $result, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'zlib_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'zlib_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'zlib_done_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $result
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
