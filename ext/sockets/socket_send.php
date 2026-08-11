<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/**
 * socket_send() — send(2) with flags (php-src ext/sockets/sockets.c; #20238).
 *
 * @see https://github.com/php/php-src/blob/master/ext/sockets/sockets.c PHP_FUNCTION(socket_send)
 */
final class socket_send extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_send');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (4 !== $argc) {
            throw new \ArgumentCountError(
                'socket_send() expects exactly 4 arguments, '.$argc.' given'
            );
        }

        $object = VmSocketArg::requireSocketObject($frame->calledArgs[0], 'socket_send', 1);
        $data = VmString::coerceTypedStringBuiltinArg($frame->calledArgs[1], 'socket_send', 1, 'data');
        $length = VmSocketArg::requireIntArg($frame, 2, 'socket_send', 3, 'length');
        $flags = VmSocketArg::requireIntArg($frame, 3, 'socket_send', 4, 'flags');
        $n = VmSockets::send($object, $data, $length, $flags, $frame);
        if (false === $n) {
            BuiltinExecute::writeReturn(
                $frame,
                static fn (Variable $ret) => $ret->bool(false)
            );

            return;
        }

        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->int($n)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('socket_send() JIT lowering not implemented (#20238)');
    }
}
