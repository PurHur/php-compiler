<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\ext\standard\JitGetObjectId;
use PHPCompiler\JIT\Builtin\StringSocketShutdown;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for socket_shutdown() via SocketCreateJitHelper::shutdownArgv (#31292).
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_shutdown)
 */
final class JitSocketShutdown
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                $argc < 1
                    ? 'socket_shutdown() expects at least 1 argument, '.$argc.' given'
                    : 'socket_shutdown() expects at most 2 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        $handle = self::socketHandle($context, $args[0]);
        $how = $argc >= 2
            ? $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[1], 'socket_shutdown() mode'),
                $context->getTypeFromString('int64')
            )
            : $context->getTypeFromString('int64')->constInt(2, false); // SHUT_RDWR

        // Resolve handle before NestedJIT ensureLinked (peer socket_close #27394).
        StringSocketShutdown::ensureLinked($context);

        $i64 = $context->getTypeFromString('int64');
        $ok = $context->builder->call(
            $context->lookupFunction('__compiler_socket_shutdown'),
            $handle,
            $how
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

    private static function socketHandle(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return JitGetObjectId::invoke($context, $arg, 'socket_shutdown');
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
            'socket_shutdown(): Argument #1 ($socket) must be of type Socket, mixed given'
        );
        $context->builder->call($context->lookupFunction('abort'));

        return $context->getTypeFromString('int64')->constInt(0, false);
    }
}
