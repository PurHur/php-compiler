<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\ext\standard\JitGetObjectId;
use PHPCompiler\JIT\Builtin\StringSocketGetSetOption;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for socket_set_option() int path (#31295).
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_set_option)
 */
final class JitSocketSetOption
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (4 !== $argc) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'socket_set_option() expects exactly 4 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        $handle = self::socketHandle($context, $args[0]);
        $i64 = $context->getTypeFromString('int64');
        $level = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[1], 'socket_set_option() level'),
            $i64
        );
        $option = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[2], 'socket_set_option() option'),
            $i64
        );
        $value = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[3], 'socket_set_option() value'),
            $i64
        );

        StringSocketGetSetOption::ensureLinked($context);
        $ok = $context->builder->call(
            $context->lookupFunction('__compiler_socket_set_option_int'),
            $handle,
            $level,
            $option,
            $value
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $truthy = $context->builder->icmp(
            Builder::INT_SGT,
            $ok,
            $i64->constInt(0, false)
        );
        $i1 = $context->getTypeFromString('int1');
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
            return JitGetObjectId::invoke($context, $arg, 'socket_set_option');
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
            'socket_set_option(): Argument #1 ($socket) must be of type Socket, mixed given'
        );
        $context->builder->call($context->lookupFunction('abort'));

        return $context->getTypeFromString('int64')->constInt(0, false);
    }
}
