<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\ext\standard\JitGetObjectId;
use PHPCompiler\JIT\Builtin\StringSocketListen;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for socket_listen() via SocketBindListenJitHelper::listenArgv (#31241).
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_listen)
 */
final class JitSocketListen
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'socket_listen() expects at least 1 argument, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        $handle = self::socketHandle($context, $args[0]);
        $backlog = self::lowerBacklogArg($context, $argc >= 2 ? $args[1] : null);

        StringSocketListen::ensureLinked($context);

        $i64 = $context->getTypeFromString('int64');
        $ok = $context->builder->call(
            $context->lookupFunction('__compiler_socket_listen'),
            $handle,
            $backlog
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i1 = $context->getTypeFromString('int1');
        $truthy = $context->builder->icmp(
            Builder::INT_SGT,
            $ok,
            $i64->constInt(0, false)
        );
        JitValueBox::writeBool($context, $slot, $context->builder->select(
            $truthy,
            $i1->constInt(1, false),
            $i1->constInt(0, false)
        ));

        return $ptr;
    }

    private static function lowerBacklogArg(Context $context, ?JITVariable $arg): Value
    {
        $i64 = $context->getTypeFromString('int64');
        if (null === $arg) {
            return $i64->constInt(0, false);
        }

        return $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $arg, 'socket_listen() backlog'),
            $i64
        );
    }

    private static function socketHandle(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return JitGetObjectId::invoke($context, $arg, 'socket_listen');
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
            'socket_listen(): Argument #1 ($socket) must be of type Socket, mixed given'
        );
        $context->builder->call($context->lookupFunction('abort'));

        return $context->getTypeFromString('int64')->constInt(0, false);
    }
}
