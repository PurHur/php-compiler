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
 * socket_write() — send(2) from string (php-src ext/sockets/sockets.c; #19286, #3399).
 *
 * @see https://github.com/php/php-src/blob/master/ext/sockets/sockets.c PHP_FUNCTION(socket_write)
 */
final class socket_write extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_write');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'socket_write() expects at least 2 arguments, '.$argc.' given'
            );
        }

        $object = VmSocketArg::requireSocketObject($frame->calledArgs[0], 'socket_write', 1);
        // Z_PARAM_STR soft-null outside strict_types (#30320).
        $buf = VmString::stringBuiltinArgForFrame($frame, 1, 'socket_write', 1, 'data', false);
        $length = null;
        if ($argc >= 3) {
            $length = VmSocketArg::requireIntArg($frame, 2, 'socket_write', 3, 'length');
        }
        $n = VmSockets::write($object, $buf, $length, $frame);
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
        return JitSocketWrite::invoke($context, ...$args);
    }
}
