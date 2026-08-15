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
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/**
 * socket_sendto() — sendto(2) AF_INET (php-src ext/sockets/sockets.c; #6248).
 *
 * @see https://github.com/php/php-src/blob/master/ext/sockets/sockets.c PHP_FUNCTION(socket_sendto)
 */
final class socket_sendto extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_sendto');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 5 || $argc > 6) {
            throw new \ArgumentCountError(
                $argc < 5
                    ? 'socket_sendto() expects at least 5 arguments, '.$argc.' given'
                    : 'socket_sendto() expects at most 6 arguments, '.$argc.' given'
            );
        }

        $object = VmSocketArg::requireSocketObject($frame->calledArgs[0], 'socket_sendto', 1);
        // Z_PARAM_STR soft-null outside strict_types (#30319).
        $data = VmString::stringBuiltinArgForFrame($frame, 1, 'socket_sendto', 1, 'data', false);
        $length = VmSocketArg::requireIntArg($frame, 2, 'socket_sendto', 3, 'length');
        $flags = VmSocketArg::requireIntArg($frame, 3, 'socket_sendto', 4, 'flags');
        $addr = VmString::stringBuiltinArgForFrame($frame, 4, 'socket_sendto', 4, 'address', false);
        $port = 0;
        if ($argc >= 6) {
            $port = VmSocketArg::requireIntArg($frame, 5, 'socket_sendto', 6, 'port');
        }
        $n = VmSockets::sendto($object, $data, $length, $flags, $addr, $port, $frame);
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
        $argc = \count($args);
        if ($argc < 5 || $argc > 6) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                $argc < 5
                    ? 'socket_sendto() expects at least 5 arguments, '.$argc.' given'
                    : 'socket_sendto() expects at most 6 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitSocketSendto::invoke($context, ...$args);
    }
}
