<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\ext\standard\JitGetObjectId;
use PHPCompiler\JIT\Builtin\StringSocketError;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for socket_last_error() via SocketErrorJitHelper::lastErrorForHandle (#31270).
 *
 * Handle extraction mirrors {@see JitSocketClose} (object / value-box / null).
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_last_error)
 */
final class JitSocketLastError
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'socket_last_error() expects at most 1 argument, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        $handle = 0 === $argc
            ? $context->getTypeFromString('int64')->constInt(0, false)
            : self::optionalSocketHandle($context, $args[0]);

        StringSocketError::ensureLinked($context);
        $errno = $context->builder->call(
            $context->lookupFunction('__compiler_socket_last_error'),
            $handle
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeLong($context, $slot, $errno);

        return $ptr;
    }

    private static function optionalSocketHandle(Context $context, JITVariable $arg): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);

        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return $zero;
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return JitGetObjectId::invoke($context, $arg, 'socket_last_error');
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            // Identical to JitSocketClose TYPE_VALUE — no runtime type diamond (#27394).
            $loaded = JitValueBox::valuePtrFromVariable($context, $arg);
            $obj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                $loaded
            );
            $voidp = $context->getTypeFromString('void')->pointerType(0);

            return $context->builder->ptrToInt(
                $context->builder->pointerCast($obj, $voidp),
                $i64
            );
        }

        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            'socket_last_error(): Argument #1 ($socket) must be of type ?Socket, mixed given'
        );
        $context->builder->call($context->lookupFunction('abort'));

        return $zero;
    }
}
