<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zmq;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;

/**
 * zmq_connect() — connect a socket to an endpoint (pecl-networking-zmq; #6443).
 */
final class zmq_connect extends ZmqFunction
{
    public function __construct()
    {
        parent::__construct('zmq_connect');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'zmq_connect() expects exactly 2 arguments, '.$argc.' given'
            );
        }
        $sockVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $sockVar->type) {
            throw new \TypeError(
                'zmq_connect(): Argument #1 ($socket) must be of type ZMQSocket, '.
                EnumCaseSupport::typeNameForVariable($sockVar).' given'
            );
        }
        $socket = $sockVar->toObject();
        $endpoint = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'zmq_connect',
            1,
            'endpoint'
        );
        $ok = VmZmq::connect($socket, $endpoint);
        BuiltinExecute::writeReturn(
            $frame,
            static function (Variable $ret) use ($ok): void {
                $ret->bool($ok);
            }
        );
    }
}
