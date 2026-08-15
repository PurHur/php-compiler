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
 * LLVM lowering for socket_clear_error() via SocketErrorJitHelper::clearErrorForHandle (#31270).
 *
 * Handle extraction mirrors {@see JitSocketClose} / {@see JitSocketLastError}.
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_clear_error)
 */
final class JitSocketClearError
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'socket_clear_error() expects at most 1 argument, '.$argc.' given'
            );

            return self::nullResult($context);
        }

        $handle = 0 === $argc
            ? $context->getTypeFromString('int64')->constInt(0, false)
            : self::optionalSocketHandle($context, $args[0]);

        StringSocketError::ensureLinked($context);
        $context->builder->call(
            $context->lookupFunction('__compiler_socket_clear_error'),
            $handle
        );

        return self::nullResult($context);
    }

    private static function nullResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $ptr
        );

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
            return JitGetObjectId::invoke($context, $arg, 'socket_clear_error');
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
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
            'socket_clear_error(): Argument #1 ($socket) must be of type ?Socket, mixed given'
        );
        $context->builder->call($context->lookupFunction('abort'));

        return $zero;
    }
}
