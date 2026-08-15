<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\JIT\Builtin\StringSocketCmsgSpace;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for socket_cmsg_space() via SocketCmsgSpaceJitHelper (#31345).
 *
 * php-src: ext/sockets/sendrecvmsg.c — PHP_FUNCTION(socket_cmsg_space)
 */
final class JitSocketCmsgSpace
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                $argc < 2
                    ? 'socket_cmsg_space() expects at least 2 arguments, '.$argc.' given'
                    : 'socket_cmsg_space() expects at most 3 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        $i64 = $context->getTypeFromString('int64');
        $level = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], 'socket_cmsg_space() level'),
            $i64
        );
        $type = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[1], 'socket_cmsg_space() type'),
            $i64
        );
        $num = 3 === $argc
            ? $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[2], 'socket_cmsg_space() num'),
                $i64
            )
            : $i64->constInt(0, false);

        StringSocketCmsgSpace::ensureLinked($context);
        $space = $context->builder->call(
            $context->lookupFunction('__compiler_socket_cmsg_space'),
            $level,
            $type,
            $num
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeLong($context, $slot, $space);

        return $ptr;
    }
}
