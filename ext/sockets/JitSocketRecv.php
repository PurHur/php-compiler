<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\ext\standard\JitGetObjectId;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringSocketSendRecv;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for socket_recv() via SocketCreateJitHelper::recvArgv (#31294).
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_recv)
 */
final class JitSocketRecv
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (4 !== $argc) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'socket_recv() expects exactly 4 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        $handle = self::socketHandle($context, $args[0]);
        $i64 = $context->getTypeFromString('int64');
        // socket_recv($socket, &$data, $length, $flags)
        $length = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[2], 'socket_recv() length'),
            $i64
        );
        $flags = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[3], 'socket_recv() flags'),
            $i64
        );

        StringSocketSendRecv::ensureLinked($context);
        $n = $context->builder->call(
            $context->lookupFunction('__compiler_socket_recv'),
            $handle,
            $length,
            $flags
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $dataPtr = JitValueBox::valuePtrFromVariable($context, $args[1]);
        $i1 = $context->getTypeFromString('int1');

        // length < 1 → helper returns -2: false without touching &$data
        $isLenBad = $context->builder->icmp(
            Builder::INT_EQ,
            $n,
            $i64->constInt(-2, true)
        );
        $lenBadBb = BasicBlockHelper::append($context, 'socket_recv_len_bad');
        $afterLenBb = BasicBlockHelper::append($context, 'socket_recv_after_len');
        $context->builder->branchIf($isLenBad, $lenBadBb, $afterLenBb);

        $context->builder->positionAtEnd($lenBadBb);
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $lenBadTail = $context->builder->getInsertBlock();
        $doneBb = BasicBlockHelper::append($context, 'socket_recv_done');
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($afterLenBb);
        $isFail = $context->builder->icmp(
            Builder::INT_EQ,
            $n,
            $i64->constInt(-1, true)
        );
        $failBb = BasicBlockHelper::append($context, 'socket_recv_fail');
        $okBb = BasicBlockHelper::append($context, 'socket_recv_ok');
        $context->builder->branchIf($isFail, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $dataPtr
        );
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $eofI = $context->builder->call(
            $context->lookupFunction('__compiler_socket_recv_eof')
        );
        $isEof = $context->builder->icmp(
            Builder::INT_SGT,
            $eofI,
            $i64->constInt(0, false)
        );
        $eofBb = BasicBlockHelper::append($context, 'socket_recv_eof');
        $dataBb = BasicBlockHelper::append($context, 'socket_recv_data');
        $context->builder->branchIf($isEof, $eofBb, $dataBb);

        $context->builder->positionAtEnd($eofBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $dataPtr
        );
        JitValueBox::writeLong($context, $slot, $i64->constInt(0, false));
        $eofTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($dataBb);
        $str = $context->builder->call(
            $context->lookupFunction('__compiler_socket_recv_data')
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $dataPtr,
            $str
        );
        JitValueBox::writeLong($context, $slot, $n);
        $dataTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $ptrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($ptrTy);
        $result->addIncoming($ptr, $lenBadTail);
        $result->addIncoming($ptr, $failTail);
        $result->addIncoming($ptr, $eofTail);
        $result->addIncoming($ptr, $dataTail);

        return $result;
    }

    private static function socketHandle(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return JitGetObjectId::invoke($context, $arg, 'socket_recv');
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
            'socket_recv(): Argument #1 ($socket) must be of type Socket, mixed given'
        );
        $context->builder->call($context->lookupFunction('abort'));

        return $context->getTypeFromString('int64')->constInt(0, false);
    }
}
