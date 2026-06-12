<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * socket_atmark() — TCP urgent-data mark probe (php-src ext/sockets/sockets.c; #6544).
 *
 * VM uses libc sockatmark(3) via {@see VmSockets} FFI; host fallback only when FFI unavailable (#7998).
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

        $object = VmSocketArg::requireSocketObject($frame->calledArgs[0], 'socket_atmark', 1);
        $atmark = VmSockets::atmarkForObject($object);
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->bool($atmark)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('socket_atmark() is not implemented for JIT in this compiler build (issue #6544)');
    }
}
