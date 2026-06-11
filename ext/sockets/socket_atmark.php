<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * socket_atmark() — TCP urgent-data mark probe (php-src ext/sockets/sockets.c; #6544).
 *
 * VM delegates to host {@see \socket_atmark()} on the wrapped {@see \Socket}.
 *
 * @see https://github.com/php/php-src/blob/master/ext/sockets/sockets.c PHP_FUNCTION(socket_atmark)
 */
final class socket_atmark extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_atmark');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'socket_atmark() expects exactly 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $hostSocket = VmSocketArg::requireHostSocket($frame->calledArgs[0], 'socket_atmark', 1);
        $frame->returnVar->bool(VmSockets::atmark($hostSocket));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('socket_atmark() is not implemented for JIT in this compiler build (issue #6544)');
    }
}
