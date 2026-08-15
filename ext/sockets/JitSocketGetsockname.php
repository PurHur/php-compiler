<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\ext\standard\JitGetObjectId;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringSocketGetName;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for socket_getsockname() via SocketGetNameRuntime (#31327).
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_getsockname)
 */
final class JitSocketGetsockname
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        return self::invokeNamed($context, false, ...$args);
    }

    /** Shared with {@see JitSocketGetpeername}. */
    public static function invokeNamed(Context $context, bool $peer, JITVariable ...$args): Value
    {
        $fn = $peer ? 'socket_getpeername' : 'socket_getsockname';
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                $argc < 2
                    ? $fn.'() expects at least 2 arguments, '.$argc.' given'
                    : $fn.'() expects at most 3 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        $handle = self::socketHandle($context, $args[0], $fn);
        StringSocketGetName::ensureLinked($context);

        $addrAbi = $peer
            ? '__compiler_socket_getpeername_addr'
            : '__compiler_socket_getsockname_addr';
        $portAbi = $peer
            ? '__compiler_socket_getpeername_port'
            : '__compiler_socket_getsockname_port';

        $addrStr = $context->builder->call(
            $context->lookupFunction($addrAbi),
            $handle
        );

        $strPtr = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $addrStr, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBb = BasicBlockHelper::append($context, $fn.'_fail');
        $okBb = BasicBlockHelper::append($context, $fn.'_ok');
        $doneBb = BasicBlockHelper::append($context, $fn.'_done');
        $context->builder->branchIf($isNull, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(0, false));
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $addrOut = JitValueBox::valuePtrFromVariable($context, $args[1]);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $addrOut,
            $addrStr
        );
        if ($argc >= 3) {
            $port = $context->builder->call(
                $context->lookupFunction($portAbi),
                $handle
            );
            $portOut = JitValueBox::valuePtrFromVariable($context, $args[2]);
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                $portOut,
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

    private static function socketHandle(Context $context, JITVariable $arg, string $fn): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return JitGetObjectId::invoke($context, $arg, $fn);
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
            \sprintf('%s(): Argument #1 ($socket) must be of type Socket, mixed given', $fn)
        );
        $context->builder->call($context->lookupFunction('abort'));

        return $context->getTypeFromString('int64')->constInt(0, false);
    }
}
