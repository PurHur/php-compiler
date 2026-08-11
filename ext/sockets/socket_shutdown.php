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
 * socket_shutdown() — shutdown(2) (php-src ext/sockets/sockets.c; #6533).
 *
 * @see https://github.com/php/php-src/blob/master/ext/sockets/sockets.c PHP_FUNCTION(socket_shutdown)
 */
final class socket_shutdown extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_shutdown');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                $argc < 1
                    ? 'socket_shutdown() expects at least 1 argument, '.$argc.' given'
                    : 'socket_shutdown() expects at most 2 arguments, '.$argc.' given'
            );
        }

        $object = VmSocketArg::requireSocketObject($frame->calledArgs[0], 'socket_shutdown', 1);
        $how = 2; // SHUT_RDWR default (php-src)
        if ($argc >= 2) {
            $how = VmSocketArg::requireIntArg($frame, 1, 'socket_shutdown', 2, 'mode');
        }
        $ok = VmSockets::shutdown($object, $how, $frame);
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->bool($ok)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('socket_shutdown() JIT lowering not implemented (#6533)');
    }
}
