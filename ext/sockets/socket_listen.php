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
 * socket_listen() — listen(2) (php-src ext/sockets/sockets.c; #6176).
 *
 * @see https://github.com/php/php-src/blob/master/ext/sockets/sockets.c PHP_FUNCTION(socket_listen)
 */
final class socket_listen extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_listen');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'socket_listen() expects at least 1 argument, '.$argc.' given'
            );
        }

        $object = VmSocketArg::requireSocketObject($frame->calledArgs[0], 'socket_listen', 1);
        $backlog = 0;
        if ($argc >= 2) {
            $backlog = VmSocketArg::requireIntArg($frame, 1, 'socket_listen', 2, 'backlog');
        }
        $ok = VmSockets::listen($object, $backlog, $frame);
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->bool($ok)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('socket_listen() JIT lowering not implemented (#6176)');
    }
}
