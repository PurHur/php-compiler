<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zmq;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;

/**
 * zmq_send() — send a message on a socket (pecl-networking-zmq; #6443).
 */
final class zmq_send extends ZmqFunction
{
    public function __construct()
    {
        parent::__construct('zmq_send');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'zmq_send() expects 2-3 arguments, '.$argc.' given'
            );
        }
        $sockVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $sockVar->type) {
            throw new \TypeError(
                'zmq_send(): Argument #1 ($socket) must be of type ZMQSocket, '.
                EnumCaseSupport::typeNameForVariable($sockVar).' given'
            );
        }
        $socket = $sockVar->toObject();
        $message = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'zmq_send',
            1,
            'message'
        );
        $ok = VmZmq::send($socket, $message);
        BuiltinExecute::writeReturn(
            $frame,
            static function (Variable $ret) use ($ok): void {
                $ret->bool($ok);
            }
        );
    }
}
