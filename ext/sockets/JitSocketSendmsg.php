<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\ext\standard\JitGetObjectId;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringSocketSendRecvMsg;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for socket_sendmsg() via PairIo write(2) (#31356).
 *
 * iov-only NestedJIT path — control/name remain VM-complete.
 * php-src: ext/sockets/sendrecvmsg.c — PHP_FUNCTION(socket_sendmsg)
 */
final class JitSocketSendmsg
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                $argc < 2
                    ? 'socket_sendmsg() expects at least 2 arguments, '.$argc.' given'
                    : 'socket_sendmsg() expects at most 3 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        // flags accepted for arity parity; write(2) path ignores them (#31356).
        if (3 === $argc) {
            JitLongArg::lower($context, $args[2], 'socket_sendmsg() flags');
        }

        $handle = self::socketHandle($context, $args[0]);
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i1 = $context->getTypeFromString('int1');

        $msgHt = HashTableHelper::loadHashtablePointer($context, $args[1]);
        $iovKey = $context->builder->load($context->constantStringFromString('iov'));
        $hasIov = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $msgHt,
            $iovKey
        );
        $noIovBb = BasicBlockHelper::append($context, 'socket_sendmsg_no_iov');
        $haveIovBb = BasicBlockHelper::append($context, 'socket_sendmsg_have_iov');
        $doneBb = BasicBlockHelper::append($context, 'socket_sendmsg_done');
        $context->builder->branchIf($hasIov, $haveIovBb, $noIovBb);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $context->builder->positionAtEnd($noIovBb);
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $noIovTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($haveIovBb);
        $iovHt = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyHashtable'),
            $msgHt,
            $iovKey
        );
        $iovNull = $context->builder->icmp(Builder::INT_EQ, $iovHt, $htPtr->constNull());
        $badIovBb = BasicBlockHelper::append($context, 'socket_sendmsg_bad_iov');
        $okIovBb = BasicBlockHelper::append($context, 'socket_sendmsg_ok_iov');
        $context->builder->branchIf($iovNull, $badIovBb, $okIovBb);

        $context->builder->positionAtEnd($badIovBb);
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $badIovTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okIovBb);
        $iov0 = HashTableHelper::readStringAt($context, $iovHt, $sizeT->constInt(0, false));
        $len = $context->builder->call(
            $context->lookupFunction('__string__strlen'),
            $iov0
        );

        StringSocketSendRecvMsg::ensureLinked($context);
        $n = $context->builder->call(
            $context->lookupFunction('__compiler_socket_write'),
            $handle,
            $iov0,
            $len
        );

        $isFail = $context->builder->icmp(
            Builder::INT_SLT,
            $n,
            $i64->constInt(0, true)
        );
        $failBb = BasicBlockHelper::append($context, 'socket_sendmsg_fail');
        $okBb = BasicBlockHelper::append($context, 'socket_sendmsg_ok');
        $context->builder->branchIf($isFail, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        JitValueBox::writeLong($context, $slot, $n);
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $ptrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($ptrTy);
        $result->addIncoming($ptr, $noIovTail);
        $result->addIncoming($ptr, $badIovTail);
        $result->addIncoming($ptr, $failTail);
        $result->addIncoming($ptr, $okTail);

        return $result;
    }

    private static function socketHandle(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return JitGetObjectId::invoke($context, $arg, 'socket_sendmsg');
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            $loaded = JitValueBox::valuePtrFromVariable($context, $arg);
            $obj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                $loaded
            );
            $voidp = $context->getTypeFromString('void')->pointerType(0);
            $i64 = $context->getTypeFromString('int64');

            return $context->builder->ptrToInt(
                $context->builder->pointerCast($obj, $voidp),
                $i64
            );
        }
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            'socket_sendmsg(): Argument #1 ($socket) must be of type Socket, mixed given'
        );
        $context->builder->call($context->lookupFunction('abort'));

        return $context->getTypeFromString('int64')->constInt(0, false);
    }
}
