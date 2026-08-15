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
 * socket_getpeername() — getpeername(2) AF_INET (php-src ext/sockets/sockets.c; #6248 / #31327).
 *
 * @see https://github.com/php/php-src/blob/master/ext/sockets/sockets.c PHP_FUNCTION(socket_getpeername)
 */
final class socket_getpeername extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_getpeername');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                $argc < 2
                    ? 'socket_getpeername() expects at least 2 arguments, '.$argc.' given'
                    : 'socket_getpeername() expects at most 3 arguments, '.$argc.' given'
            );
        }

        $object = VmSocketArg::requireSocketObject($frame->calledArgs[0], 'socket_getpeername', 1);
        $name = VmSockets::getpeername($object, $frame);
        if (false === $name) {
            BuiltinExecute::writeReturn(
                $frame,
                static fn (Variable $ret) => $ret->bool(false)
            );

            return;
        }

        $addrOut = new Variable();
        $addrOut->string($name[0]);
        $frame->calledArgs[1]->byRefTarget()->copyFrom($addrOut);
        if ($argc >= 3) {
            $portOut = new Variable();
            $portOut->int($name[1]);
            $frame->calledArgs[2]->byRefTarget()->copyFrom($portOut);
        }

        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->bool(true)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                $argc < 2
                    ? 'socket_getpeername() expects at least 2 arguments, '.$argc.' given'
                    : 'socket_getpeername() expects at most 3 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitSocketGetpeername::invoke($context, ...$args);
    }
}
