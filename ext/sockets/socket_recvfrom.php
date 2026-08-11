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
 * socket_recvfrom() — recvfrom(2) AF_INET (php-src ext/sockets/sockets.c; #6248).
 *
 * @see https://github.com/php/php-src/blob/master/ext/sockets/sockets.c PHP_FUNCTION(socket_recvfrom)
 */
final class socket_recvfrom extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_recvfrom');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 5 || $argc > 6) {
            throw new \ArgumentCountError(
                $argc < 5
                    ? 'socket_recvfrom() expects at least 5 arguments, '.$argc.' given'
                    : 'socket_recvfrom() expects at most 6 arguments, '.$argc.' given'
            );
        }

        $object = VmSocketArg::requireSocketObject($frame->calledArgs[0], 'socket_recvfrom', 1);
        $length = VmSocketArg::requireIntArg($frame, 2, 'socket_recvfrom', 3, 'length');
        $flags = VmSocketArg::requireIntArg($frame, 3, 'socket_recvfrom', 4, 'flags');
        $got = VmSockets::recvfrom($object, $length, $flags, $frame);
        if (false === $got) {
            BuiltinExecute::writeReturn(
                $frame,
                static fn (Variable $ret) => $ret->bool(false)
            );

            return;
        }

        $dataOut = new Variable();
        $dataOut->string($got[0]);
        $frame->calledArgs[1]->byRefTarget()->copyFrom($dataOut);
        $addrOut = new Variable();
        $addrOut->string($got[1]);
        $frame->calledArgs[4]->byRefTarget()->copyFrom($addrOut);
        if ($argc >= 6) {
            $portOut = new Variable();
            $portOut->int($got[2]);
            $frame->calledArgs[5]->byRefTarget()->copyFrom($portOut);
        }

        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->int(\strlen($got[0]))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('socket_recvfrom() JIT lowering not implemented (#6248)');
    }
}
