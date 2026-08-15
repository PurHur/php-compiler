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
 * socket_accept() — accept(2) as Socket (php-src ext/sockets/sockets.c; #6176).
 *
 * @see https://github.com/php/php-src/blob/master/ext/sockets/sockets.c PHP_FUNCTION(socket_accept)
 */
final class socket_accept extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_accept');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'socket_accept() expects exactly 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('socket_accept() requires VM context');
        }

        $object = VmSocketArg::requireSocketObject($frame->calledArgs[0], 'socket_accept', 1);
        $client = VmSockets::accept($object, $frame->vmContext, $frame);
        if (false === $client) {
            BuiltinExecute::writeReturn(
                $frame,
                static fn (Variable $ret) => $ret->bool(false)
            );

            return;
        }

        BuiltinExecute::writeReturn(
            $frame,
            static function (Variable $ret) use ($client): void {
                $ret->object($client);
            }
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitSocketAccept::invoke($context, ...$args);
    }
}
