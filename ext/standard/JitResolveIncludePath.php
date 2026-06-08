<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\IncludePathRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for stream_resolve_include_path() (issue #6051). */
final class JitResolveIncludePath
{
    public static function invoke(Context $context, Value $filename): Value
    {
        IncludePathRuntime::ensureLinked($context);
        $resolved = $context->builder->call(
            $context->lookupFunction('__compiler_stream_resolve_include_path'),
            $filename
        );
        $strPtrTy = $context->getTypeFromString('__string__*');
        $isFalse = $context->builder->icmp(Builder::INT_EQ, $resolved, $strPtrTy->constNull());
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'resolve_inc_fail');
        $okBlock = BasicBlockHelper::append($context, 'resolve_inc_ok');
        $doneBlock = BasicBlockHelper::append($context, 'resolve_inc_done');
        $context->builder->branchIf($isFalse, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $resolved);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
