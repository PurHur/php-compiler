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
 * socket_read() — recv(2) into string (php-src ext/sockets/sockets.c; #19286, #3399).
 *
 * @see https://github.com/php/php-src/blob/master/ext/sockets/sockets.c PHP_FUNCTION(socket_read)
 */
final class socket_read extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_read');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'socket_read() expects at least 2 arguments, '.$argc.' given'
            );
        }

        $object = VmSocketArg::requireSocketObject($frame->calledArgs[0], 'socket_read', 1);
        $length = VmSocketArg::requireIntArg($frame, 1, 'socket_read', 2, 'length');
        $type = VmSockets::PHP_BINARY_READ;
        if ($argc >= 3) {
            $type = VmSocketArg::requireIntArg($frame, 2, 'socket_read', 3, 'mode');
        }
        $data = VmSockets::read($object, $length, $type);
        if (false === $data) {
            BuiltinExecute::writeReturn(
                $frame,
                static fn (Variable $ret) => $ret->bool(false)
            );

            return;
        }

        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string($data)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitSocketRead::invoke($context, ...$args);
    }
}
