<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zmq;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/**
 * zmq_msg_read() — receive next message payload (pecl-networking-zmq; #6443).
 *
 * Phase-0: same as zmq_recv() for string messages.
 */
final class zmq_msg_read extends ZmqFunction
{
    public function __construct()
    {
        parent::__construct('zmq_msg_read');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'zmq_msg_read() expects 1-2 arguments, '.$argc.' given'
            );
        }
        $sockVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $sockVar->type) {
            throw new \TypeError(
                'zmq_msg_read(): Argument #1 ($socket) must be of type ZMQSocket, '.
                EnumCaseSupport::typeNameForVariable($sockVar).' given'
            );
        }
        $socket = $sockVar->toObject();
        $msg = VmZmq::recv($socket);
        BuiltinExecute::writeReturn(
            $frame,
            static function (Variable $ret) use ($msg): void {
                if (false === $msg) {
                    $ret->bool(false);
                } else {
                    $ret->string($msg);
                }
            }
        );
    }
}
