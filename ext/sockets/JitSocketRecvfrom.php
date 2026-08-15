<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\ext\standard\JitGetObjectId;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringSocketRecvfrom;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for socket_recvfrom() via SocketCreateJitHelper::recvfromArgv (#31332).
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_recvfrom)
 */
final class JitSocketRecvfrom
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 5 || $argc > 6) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                $argc < 5
                    ? 'socket_recvfrom() expects at least 5 arguments, '.$argc.' given'
                    : 'socket_recvfrom() expects at most 6 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        $handle = self::socketHandle($context, $args[0]);
        $i64 = $context->getTypeFromString('int64');
        // socket_recvfrom($socket, &$data, $length, $flags, &$address [, &$port])
        $length = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[2], 'socket_recvfrom() length'),
            $i64
        );
        $flags = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[3], 'socket_recvfrom() flags'),
            $i64
        );

        StringSocketRecvfrom::ensureLinked($context);
        $n = $context->builder->call(
            $context->lookupFunction('__compiler_socket_recvfrom'),
            $handle,
            $length,
            $flags
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $dataPtr = JitValueBox::valuePtrFromVariable($context, $args[1]);
        $addrPtr = JitValueBox::valuePtrFromVariable($context, $args[4]);
        $i1 = $context->getTypeFromString('int1');

        $isLenBad = $context->builder->icmp(
            Builder::INT_EQ,
            $n,
            $i64->constInt(-2, true)
        );
        $lenBadBb = BasicBlockHelper::append($context, 'socket_recvfrom_len_bad');
        $afterLenBb = BasicBlockHelper::append($context, 'socket_recvfrom_after_len');
        $context->builder->branchIf($isLenBad, $lenBadBb, $afterLenBb);

        $context->builder->positionAtEnd($lenBadBb);
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $lenBadTail = $context->builder->getInsertBlock();
        $doneBb = BasicBlockHelper::append($context, 'socket_recvfrom_done');
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($afterLenBb);
        $isFail = $context->builder->icmp(
            Builder::INT_EQ,
            $n,
            $i64->constInt(-1, true)
        );
        $failBb = BasicBlockHelper::append($context, 'socket_recvfrom_fail');
        $okBb = BasicBlockHelper::append($context, 'socket_recvfrom_ok');
        $context->builder->branchIf($isFail, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $dataStr = $context->builder->call(
            $context->lookupFunction('__compiler_socket_recvfrom_data')
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $dataPtr,
            $dataStr
        );
        $addrStr = $context->builder->call(
            $context->lookupFunction('__compiler_socket_recvfrom_addr')
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $addrPtr,
            $addrStr
        );
        if ($argc >= 6) {
            $port = $context->builder->call(
                $context->lookupFunction('__compiler_socket_recvfrom_port')
            );
            $portPtr = JitValueBox::valuePtrFromVariable($context, $args[5]);
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                $portPtr,
                $port
            );
        }
        JitValueBox::writeLong($context, $slot, $n);
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $ptrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($ptrTy);
        $result->addIncoming($ptr, $lenBadTail);
        $result->addIncoming($ptr, $failTail);
        $result->addIncoming($ptr, $okTail);

        return $result;
    }

    private static function socketHandle(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return JitGetObjectId::invoke($context, $arg, 'socket_recvfrom');
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
            'socket_recvfrom(): Argument #1 ($socket) must be of type Socket, mixed given'
        );
        $context->builder->call($context->lookupFunction('abort'));

        return $context->getTypeFromString('int64')->constInt(0, false);
    }
}
