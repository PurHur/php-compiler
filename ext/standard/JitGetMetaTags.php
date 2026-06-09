<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MetaTagsRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for get_meta_tags() via {@see MetaTagsRuntime}. */
final class JitGetMetaTags
{
    public static function invoke(Context $context, Value $pathStr, Value $useIncludePath): Value
    {
        MetaTagsRuntime::ensureLinked($context);

        $ht = $context->builder->call(
            $context->lookupFunction('__compiler_get_meta_tags'),
            $pathStr,
            $useIncludePath
        );
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtrTy->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'meta_tags_fail');
        $okBlock = BasicBlockHelper::append($context, 'meta_tags_ok');
        $doneBlock = BasicBlockHelper::append($context, 'meta_tags_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
