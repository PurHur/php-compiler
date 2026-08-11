<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmStreamArg;
use PHPLLVM\Value;

/**
 * socket_sendmsg() — sendmsg(2) (php-src ext/sockets/sendrecvmsg.c; #6333).
 */
final class socket_sendmsg extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_sendmsg');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                $argc < 2
                    ? 'socket_sendmsg() expects at least 2 arguments, '.$argc.' given'
                    : 'socket_sendmsg() expects at most 3 arguments, '.$argc.' given'
            );
        }
        $object = VmSocketArg::requireSocketObject($frame->calledArgs[0], 'socket_sendmsg', 1);
        $msgVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $msgVar->type) {
            throw new \TypeError(\sprintf(
                'socket_sendmsg(): Argument #2 ($message) must be of type array, %s given',
                VmStreamArg::debugTypeName($msgVar)
            ));
        }
        $flags = 0;
        if ($argc >= 3) {
            $flags = VmSocketArg::requireIntArg($frame, 2, 'socket_sendmsg', 3, 'flags');
        }
        $parsed = VmSocketMsg::parseSendMessage($msgVar->toArray(), $frame);
        if (null === $parsed) {
            BuiltinExecute::writeReturn(
                $frame,
                static fn (Variable $ret) => $ret->bool(false)
            );

            return;
        }
        $n = VmSocketMsg::sendmsg($object, $parsed, $flags, $frame);
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
        throw new \LogicException('socket_sendmsg() JIT lowering not implemented (#6333)');
    }
}
