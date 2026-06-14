<?php

declare(strict_types=1);

namespace PHPCompiler\ext\lzf;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringLzf;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM helpers for lzf_compress()/lzf_decompress() — liblzf via StringLzfJit (#6384). */
final class JitLzf
{
    private static int $blockSerial = 0;

    public static function compress(Context $context, Value $source): Value
    {
        StringLzf::ensureLinked($context);

        return self::stringOrFalse($context, $context->builder->call(
            $context->lookupFunction('__compiler_lzf_compress'),
            $source
        ));
    }

    public static function decompress(Context $context, Value $source): Value
    {
        StringLzf::ensureLinked($context);

        return self::stringOrFalse($context, $context->builder->call(
            $context->lookupFunction('__compiler_lzf_decompress'),
            $source
        ));
    }

    private static function stringOrFalse(Context $context, Value $result): Value
    {
        $id = (string) (++self::$blockSerial);
        $strPtr = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $result, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'lzf_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'lzf_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'lzf_done_'.$id);
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
