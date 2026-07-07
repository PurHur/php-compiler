<?php

declare(strict_types=1);

namespace PHPCompiler\ext\brotli;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringBrotli;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM helpers for brotli_*() — BrotliJitHelper in-module (#6814). */
final class JitBrotli
{
    private static int $blockSerial = 0;

    public static function compress(Context $context, Value $data, Value $level, Value $mode): Value
    {
        StringBrotli::ensureLinked($context);

        return self::stringOrFalse($context, $context->builder->call(
            StringBrotli::compressHelper($context),
            $data,
            $level,
            $mode
        ));
    }

    public static function uncompress(Context $context, Value $data): Value
    {
        StringBrotli::ensureLinked($context);

        return self::stringOrFalse($context, $context->builder->call(
            StringBrotli::uncompressHelper($context),
            $data
        ));
    }

    public static function defaultLevel(Context $context): Value
    {
        return $context->getTypeFromString('int64')->constInt(VmBrotliNative::DEFAULT_QUALITY, false);
    }

    public static function defaultMode(Context $context): Value
    {
        return $context->getTypeFromString('int64')->constInt(VmBrotliNative::MODE_GENERIC, false);
    }

    private static function stringOrFalse(Context $context, Value $result): Value
    {
        $id = (string) (++self::$blockSerial);
        $strPtr = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $result, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'brotli_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'brotli_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'brotli_done_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeString($context, $slot, $result);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return JitValueBox::read($context, $ptr);
    }
}
