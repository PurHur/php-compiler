<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for socket_addrinfo_bind() via SocketAddrinfoJitHelper (#31357).
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_addrinfo_bind)
 */
final class JitSocketAddrinfoBind
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        return JitSocketAddrinfoConnect::invokeOp($context, $args, 1, 'socket_addrinfo_bind');
    }
}
