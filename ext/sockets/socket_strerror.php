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
 * socket_strerror() — strerror(3) for socket errno (php-src ext/sockets/sockets.c; #6227).
 *
 * @see https://github.com/php/php-src/blob/master/ext/sockets/sockets.c PHP_FUNCTION(socket_strerror)
 */
final class socket_strerror extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_strerror');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'socket_strerror() expects exactly 1 argument, '.$argc.' given'
            );
        }
        $errno = VmSocketArg::requireIntArg($frame, 0, 'socket_strerror', 1, 'error_code');
        $msg = VmSockets::strerror($errno);
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string($msg)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitSocketStrerror::invoke($context, ...$args);
    }
}
