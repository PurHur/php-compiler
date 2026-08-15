<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for socket_getpeername() via SocketGetNameRuntime (#31327).
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_getpeername)
 */
final class JitSocketGetpeername
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        return JitSocketGetsockname::invokeNamed($context, true, ...$args);
    }
}
