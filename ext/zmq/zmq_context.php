<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zmq;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/**
 * zmq_context() — create a ZeroMQ context (pecl-networking-zmq; #6443).
 */
final class zmq_context extends ZmqFunction
{
    public function __construct()
    {
        parent::__construct('zmq_context');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'zmq_context() accepts at most 1 argument, '.$argc.' given'
            );
        }
        $ctx = $frame->vmContext;
        $object = VmZmq::createContext($ctx);
        BuiltinExecute::writeReturn(
            $frame,
            static function (Variable $ret) use ($object): void {
                $ret->object($object);
            }
        );
    }
}
