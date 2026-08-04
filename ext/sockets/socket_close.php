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
 * socket_close() — close(2) for owned Socket fds (php-src ext/sockets/sockets.c; #19286, #3399).
 *
 * @see https://github.com/php/php-src/blob/master/ext/sockets/sockets.c PHP_FUNCTION(socket_close)
 */
final class socket_close extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_close');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'socket_close() expects exactly 1 argument, '.$argc.' given'
            );
        }

        $object = VmSocketArg::requireSocketObject($frame->calledArgs[0], 'socket_close', 1);
        VmSockets::close($object);
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->null()
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            return JitSocketClose::emitArgumentCountError($context, $argc);
        }

        return JitSocketClose::invoke($context, $args[0]);
    }
}
