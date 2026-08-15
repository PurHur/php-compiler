<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\ext\standard\JitGetObjectId;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringSocketSendto;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for socket_sendto() via SocketCreateJitHelper::sendtoArgv (#31308).
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_sendto)
 */
final class JitSocketSendto
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 5 || $argc > 6) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                $argc < 5
                    ? 'socket_sendto() expects at least 5 arguments, '.$argc.' given'
                    : 'socket_sendto() expects at most 6 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        $handle = self::socketHandle($context, $args[0]);
        $data = JitStringArg::lower($context, $args[1], 'socket_sendto() data');
        $i64 = $context->getTypeFromString('int64');
        $length = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[2], 'socket_sendto() length'),
            $i64
        );
        $flags = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[3], 'socket_sendto() flags'),
            $i64
        );
        $addr = JitStringArg::lower($context, $args[4], 'socket_sendto() address');
        $port = $argc >= 6
            ? $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[5], 'socket_sendto() port'),
                $i64
            )
            : $i64->constInt(0, false);

        // Resolve handle before NestedJIT ensureLinked (peer socket_write #27423).
        StringSocketSendto::ensureLinked($context);

        $n = $context->builder->call(
            $context->lookupFunction('__compiler_socket_sendto'),
            $handle,
            $data,
            $length,
            $flags,
            $addr,
            $port
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $neg = $context->builder->icmp(Builder::INT_SLT, $n, $i64->constInt(0, true));
        $failBb = BasicBlockHelper::append($context, 'socket_sendto_fail');
        $okBb = BasicBlockHelper::append($context, 'socket_sendto_ok');
        $doneBb = BasicBlockHelper::append($context, 'socket_sendto_done');
        $context->builder->branchIf($neg, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(0, false));
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        JitValueBox::writeLong($context, $slot, $n);
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $ptrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($ptrTy);
        $result->addIncoming($ptr, $failTail);
        $result->addIncoming($ptr, $okTail);

        return $result;
    }

    private static function socketHandle(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return JitGetObjectId::invoke($context, $arg, 'socket_sendto');
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
            'socket_sendto(): Argument #1 ($socket) must be of type Socket, mixed given'
        );
        $context->builder->call($context->lookupFunction('abort'));

        return $context->getTypeFromString('int64')->constInt(0, false);
    }
}
