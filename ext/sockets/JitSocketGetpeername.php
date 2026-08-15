<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\ext\standard\JitGetObjectId;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringSocketGetSockname;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for socket_getpeername() via SocketCreateJitHelper (#31293).
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_getpeername)
 */
final class JitSocketGetpeername
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                $argc < 2
                    ? 'socket_getpeername() expects at least 2 arguments, '.$argc.' given'
                    : 'socket_getpeername() expects at most 3 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        $handle = self::socketHandle($context, $args[0]);
        StringSocketGetSockname::ensureLinked($context);

        $i64 = $context->getTypeFromString('int64');
        $ok = $context->builder->call(
            $context->lookupFunction('__compiler_socket_getpeername'),
            $handle
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $truthy = $context->builder->icmp(
            Builder::INT_SGT,
            $ok,
            $i64->constInt(0, false)
        );
        $failBb = BasicBlockHelper::append($context, 'socket_getpeername_fail');
        $okBb = BasicBlockHelper::append($context, 'socket_getpeername_ok');
        $doneBb = BasicBlockHelper::append($context, 'socket_getpeername_done');
        $context->builder->branchIf($truthy, $okBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(0, false));
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $addrStr = $context->builder->call(
            $context->lookupFunction('__compiler_socket_name_addr')
        );
        $addrPtr = JitValueBox::valuePtrFromVariable($context, $args[1]);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $addrPtr,
            $addrStr
        );
        if ($argc >= 3) {
            $port = $context->builder->call(
                $context->lookupFunction('__compiler_socket_name_port')
            );
            $portPtr = JitValueBox::valuePtrFromVariable($context, $args[2]);
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                $portPtr,
                $port
            );
        }
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(1, false));
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
            return JitGetObjectId::invoke($context, $arg, 'socket_getpeername');
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
            'socket_getpeername(): Argument #1 ($socket) must be of type Socket, mixed given'
        );
        $context->builder->call($context->lookupFunction('abort'));

        return $context->getTypeFromString('int64')->constInt(0, false);
    }
}
