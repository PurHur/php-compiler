<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for stream_socket_pair() via __compiler_stream_socket_pair (#3437). */
final class JitStreamSocketPair
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \LogicException('stream_socket_pair() requires exactly three arguments in this compiler build');
        }

        $domain = JitLongArg::lower($context, $args[0], 'stream_socket_pair() domain');
        $type = JitLongArg::lower($context, $args[1], 'stream_socket_pair() type');
        $protocol = JitLongArg::lower($context, $args[2], 'stream_socket_pair() protocol');

        $ht = $context->builder->call(
            $context->lookupFunction('__compiler_stream_socket_pair'),
            $domain,
            $type,
            $protocol
        );
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'stream_socket_pair_fail');
        $okBlock = BasicBlockHelper::append($context, 'stream_socket_pair_ok');
        $doneBlock = BasicBlockHelper::append($context, 'stream_socket_pair_done');
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
        $context->refcount->addref($ht);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
