<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\GetHeadersRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for get_headers() via {@see GetHeadersRuntime}. */
final class JitGetHeaders
{
    public static function invoke(Context $context, Value $urlStr, Value $associative): Value
    {
        GetHeadersRuntime::ensureLinked($context);

        $ht = $context->builder->call(
            $context->lookupFunction('__compiler_get_headers'),
            $urlStr,
            $associative
        );
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtrTy->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'get_headers_fail');
        $okBlock = BasicBlockHelper::append($context, 'get_headers_ok');
        $doneBlock = BasicBlockHelper::append($context, 'get_headers_done');
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
