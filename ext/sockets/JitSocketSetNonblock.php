<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\ext\standard\JitGetObjectId;
use PHPCompiler\JIT\Builtin\StringSocketSetBlock;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for socket_set_nonblock() via SocketSetBlockRuntime (#31285).
 *
 * Resolve the Socket handle before NestedJIT ensureLinked — same ordering as socket_close (#27394).
 * Handle lowering matches socket_write (#27423) for create_pair value-box sockets.
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_set_nonblock)
 */
final class JitSocketSetNonblock
{
    public static function invoke(Context $context, JITVariable $arg): Value
    {
        $handle = self::socketHandle($context, $arg);
        StringSocketSetBlock::ensureLinked($context);

        $i32 = $context->getTypeFromString('int32');
        $okI32 = $context->builder->call(
            $context->lookupFunction('__compiler_socket_set_nonblock'),
            $handle
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $okBool = $context->builder->icmp(Builder::INT_NE, $okI32, $i32->constInt(0, false));
        JitValueBox::writeBool($context, $slot, $okBool);

        return $ptr;
    }

    private static function socketHandle(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return JitGetObjectId::invoke($context, $arg, 'socket_set_nonblock');
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
            'socket_set_nonblock(): Argument #1 ($socket) must be of type Socket, mixed given'
        );
        $context->builder->call($context->lookupFunction('abort'));

        return $context->getTypeFromString('int64')->constInt(0, false);
    }
}
