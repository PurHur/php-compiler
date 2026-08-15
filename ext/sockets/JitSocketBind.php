<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\ext\standard\JitGetObjectId;
use PHPCompiler\JIT\Builtin\StringSocketBind;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for socket_bind() via SocketBindListenJitHelper::bindArgv (#31241).
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_bind)
 */
final class JitSocketBind
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'socket_bind() expects at least 2 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        $handle = self::socketHandle($context, $args[0]);
        $addr = JitStringArg::lower($context, $args[1], 'socket_bind() address');
        $port = self::lowerPortArg($context, $argc >= 3 ? $args[2] : null);

        // Resolve handle before NestedJIT ensureLinked — same ordering as socket_close (#27394).
        StringSocketBind::ensureLinked($context);

        $i64 = $context->getTypeFromString('int64');
        $ok = $context->builder->call(
            $context->lookupFunction('__compiler_socket_bind'),
            $handle,
            $addr,
            $port
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

    private static function lowerPortArg(Context $context, ?JITVariable $arg): Value
    {
        $i64 = $context->getTypeFromString('int64');
        if (null === $arg) {
            return $i64->constInt(0, false);
        }

        return $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $arg, 'socket_bind() port'),
            $i64
        );
    }

    private static function socketHandle(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return JitGetObjectId::invoke($context, $arg, 'socket_bind');
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
            'socket_bind(): Argument #1 ($socket) must be of type Socket, mixed given'
        );
        $context->builder->call($context->lookupFunction('abort'));

        return $context->getTypeFromString('int64')->constInt(0, false);
    }
}
