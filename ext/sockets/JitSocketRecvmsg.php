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
 * LLVM lowering for socket_recvmsg() via PairIo read(2) (#31356).
 *
 * Reads buffer_size/controllen from &$message; rewrites message with iov+flags on success.
 * php-src: ext/sockets/sendrecvmsg.c — PHP_FUNCTION(socket_recvmsg)
 */
final class JitSocketRecvmsg
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                $argc < 2
                    ? 'socket_recvmsg() expects at least 2 arguments, '.$argc.' given'
                    : 'socket_recvmsg() expects at most 3 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        if (3 === $argc) {
            JitLongArg::lower($context, $args[2], 'socket_recvmsg() flags');
        }

        $handle = self::socketHandle($context, $args[0]);
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $strPtr = $context->getTypeFromString('__string__*');

        $msgHt = HashTableHelper::loadHashtablePointer($context, $args[1]);
        $controllenKey = $context->builder->load($context->constantStringFromString('controllen'));
        $hasControllen = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $msgHt,
            $controllenKey
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $doneBb = BasicBlockHelper::append($context, 'socket_recvmsg_done');
        $noCtrlBb = BasicBlockHelper::append($context, 'socket_recvmsg_no_ctrl');
        $haveCtrlBb = BasicBlockHelper::append($context, 'socket_recvmsg_have_ctrl');
        $context->builder->branchIf($hasControllen, $haveCtrlBb, $noCtrlBb);

        $context->builder->positionAtEnd($noCtrlBb);
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $noCtrlTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($haveCtrlBb);
        $ctrlVal = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyValue'),
            $msgHt,
            $controllenKey
        );
        $controllen = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $ctrlVal
        );
        $ctrlBad = $context->builder->icmp(
            Builder::INT_SLE,
            $controllen,
            $i64->constInt(0, true)
        );
        $ctrlBadBb = BasicBlockHelper::append($context, 'socket_recvmsg_ctrl_bad');
        $afterCtrlBb = BasicBlockHelper::append($context, 'socket_recvmsg_after_ctrl');
        $context->builder->branchIf($ctrlBad, $ctrlBadBb, $afterCtrlBb);

        $context->builder->positionAtEnd($ctrlBadBb);
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $ctrlBadTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($afterCtrlBb);
        $bufKey = $context->builder->load($context->constantStringFromString('buffer_size'));
        $hasBuf = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $msgHt,
            $bufKey
        );
        $defaultBufBb = BasicBlockHelper::append($context, 'socket_recvmsg_default_buf');
        $readBufBb = BasicBlockHelper::append($context, 'socket_recvmsg_read_buf');
        $afterBufBb = BasicBlockHelper::append($context, 'socket_recvmsg_after_buf');
        $context->builder->branchIf($hasBuf, $readBufBb, $defaultBufBb);

        $context->builder->positionAtEnd($defaultBufBb);
        $context->builder->branch($afterBufBb);

        $context->builder->positionAtEnd($readBufBb);
        $bufVal = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyValue'),
            $msgHt,
            $bufKey
        );
        $bufLong = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $bufVal
        );
        $readBufTail = $context->builder->getInsertBlock();
        $context->builder->branch($afterBufBb);

        $context->builder->positionAtEnd($afterBufBb);
        $bufferSize = $context->builder->phi($i64);
        $bufferSize->addIncoming($i64->constInt(8192, false), $defaultBufBb);
        $bufferSize->addIncoming($bufLong, $readBufTail);
        $bufOk = $context->builder->icmp(
            Builder::INT_SGT,
            $bufferSize,
            $i64->constInt(0, true)
        );
        $bufferSize = $context->builder->select(
            $bufOk,
            $bufferSize,
            $i64->constInt(1, false)
        );

        StringSocketSendRecvMsg::ensureLinked($context);
        $iovStr = $context->builder->call(
            $context->lookupFunction('__compiler_socket_read'),
            $handle,
            $bufferSize
        );
        $isNull = $context->builder->icmp(Builder::INT_EQ, $iovStr, $strPtr->constNull());
        $failBb = BasicBlockHelper::append($context, 'socket_recvmsg_fail');
        $okBb = BasicBlockHelper::append($context, 'socket_recvmsg_ok');
        $context->builder->branchIf($isNull, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $n = $context->builder->call(
            $context->lookupFunction('__string__strlen'),
            $iovStr
        );

        $outHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $iovHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $iovHt,
            $sizeT->constInt(0, false),
            $iovStr
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyHashtable'),
            $outHt,
            $context->builder->load($context->constantStringFromString('iov')),
            $iovHt
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $outHt,
            $context->builder->load($context->constantStringFromString('flags')),
            $i64->constInt(0, false)
        );
        $controlHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyHashtable'),
            $outHt,
            $context->builder->load($context->constantStringFromString('control')),
            $controlHt
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyNull'),
            $outHt,
            $context->builder->load($context->constantStringFromString('name'))
        );

        $msgPtr = JitValueBox::valuePtrFromVariable($context, $args[1]);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $msgPtr,
            $outHt
        );
        $context->refcount->addref($outHt);
        JitValueBox::writeLong($context, $slot, $n);
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $ptrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($ptrTy);
        $result->addIncoming($ptr, $noCtrlTail);
        $result->addIncoming($ptr, $ctrlBadTail);
        $result->addIncoming($ptr, $failTail);
        $result->addIncoming($ptr, $okTail);

        return $result;
    }

    private static function socketHandle(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return JitGetObjectId::invoke($context, $arg, 'socket_recvmsg');
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
            'socket_recvmsg(): Argument #1 ($socket) must be of type Socket, mixed given'
        );
        $context->builder->call($context->lookupFunction('abort'));

        return $context->getTypeFromString('int64')->constInt(0, false);
    }
}
