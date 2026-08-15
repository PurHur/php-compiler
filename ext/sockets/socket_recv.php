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
 * socket_recv() — recv(2) with flags into by-ref buffer (php-src ext/sockets/sockets.c; #20238).
 *
 * @see https://github.com/php/php-src/blob/master/ext/sockets/sockets.c PHP_FUNCTION(socket_recv)
 */
final class socket_recv extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_recv');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (4 !== $argc) {
            throw new \ArgumentCountError(
                'socket_recv() expects exactly 4 arguments, '.$argc.' given'
            );
        }

        $object = VmSocketArg::requireSocketObject($frame->calledArgs[0], 'socket_recv', 1);
        $length = VmSocketArg::requireIntArg($frame, 2, 'socket_recv', 3, 'length');
        $flags = VmSocketArg::requireIntArg($frame, 3, 'socket_recv', 4, 'flags');
        // php-src overflow guard: (len + 1) < 2 → RETURN_FALSE without touching &$data
        if ($length < 1) {
            BuiltinExecute::writeReturn(
                $frame,
                static fn (Variable $ret) => $ret->bool(false)
            );

            return;
        }
        $got = VmSockets::recv($object, $length, $flags, $frame);
        if (false === $got) {
            $null = new Variable();
            $null->null();
            $frame->calledArgs[1]->byRefTarget()->copyFrom($null);
            BuiltinExecute::writeReturn(
                $frame,
                static fn (Variable $ret) => $ret->bool(false)
            );

            return;
        }

        if (null === $got[0]) {
            $null = new Variable();
            $null->null();
            $frame->calledArgs[1]->byRefTarget()->copyFrom($null);
        } else {
            $dataOut = new Variable();
            $dataOut->string($got[0]);
            $frame->calledArgs[1]->byRefTarget()->copyFrom($dataOut);
        }

        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->int($got[1])
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitSocketRecv::invoke($context, ...$args);
    }
}
