<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zmq;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmMath;

/**
 * zmq_socket() — create a ZeroMQ socket (pecl-networking-zmq; #6443).
 */
final class zmq_socket extends ZmqFunction
{
    public function __construct()
    {
        parent::__construct('zmq_socket');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'zmq_socket() expects exactly 2 arguments, '.$argc.' given'
            );
        }
        $ctxVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $ctxVar->type) {
            throw new \TypeError(
                'zmq_socket(): Argument #1 ($context) must be of type ZMQContext, '.
                EnumCaseSupport::typeNameForVariable($ctxVar).' given'
            );
        }
        $context = $ctxVar->toObject();
        if (!VmZmq::isContext($context)) {
            throw new \TypeError(
                'zmq_socket(): Argument #1 ($context) must be of type ZMQContext'
            );
        }
        $type = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'zmq_socket', 2, 'type');
        $object = VmZmq::createSocket($context, $type, $frame->vmContext);
        BuiltinExecute::writeReturn(
            $frame,
            static function (Variable $ret) use ($object): void {
                $ret->object($object);
            }
        );
    }
}
