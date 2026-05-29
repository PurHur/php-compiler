<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\FiberSupport;
use PHPCompiler\VM\Variable;

/** Fiber::__construct(callable $callback) — VM (#3130). */
final class FiberConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('Fiber::__construct() expects exactly 1 argument');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('Fiber::__construct() called without $this');
        }
        $object = $receiver->toObject();
        if ('fiber' !== strtolower($object->class->name)) {
            throw new \LogicException('Fiber::__construct() called on invalid object');
        }
        FiberSupport::attachCallback($object, $frame->calledArgs[1]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }
}
