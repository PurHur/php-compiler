<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\JIT\Builtin\StringSocketError;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for socket_strerror() via SocketErrorJitHelper::strerrorArgv (#31270).
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_strerror)
 */
final class JitSocketStrerror
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'socket_strerror() expects exactly 1 argument, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        $i64 = $context->getTypeFromString('int64');
        $errno = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], 'socket_strerror() error_code'),
            $i64
        );

        StringSocketError::ensureLinked($context);
        $str = $context->builder->call(
            $context->lookupFunction('__compiler_socket_strerror'),
            $errno
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $str
        );

        return $ptr;
    }
}
