<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * socket_set_nonblock() — enable O_NONBLOCK on a Socket (php-src ext/sockets/sockets.c; #6289).
 *
 * VM uses libc fcntl(2) via {@see VmSockets} FFI — no host delegation.
 *
 * @see https://github.com/php/php-src/blob/master/ext/sockets/sockets.c PHP_FUNCTION(socket_set_nonblock)
 */
final class socket_set_nonblock extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_set_nonblock');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'socket_set_nonblock() expects exactly 1 argument, '.$argc.' given'
            );
        }

        $object = VmSocketArg::requireSocketObject($frame->calledArgs[0], 'socket_set_nonblock', 1);
        $ok = VmSockets::setNonblockForObject($object);
        if (!$ok) {
            VmSockets::triggerWarning($frame, 'socket_set_nonblock(): Unable to set non blocking mode');
        }
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->bool($ok)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'socket_set_nonblock() expects exactly 1 argument, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitSocketSetNonblock::invoke($context, $args[0]);
    }
}
