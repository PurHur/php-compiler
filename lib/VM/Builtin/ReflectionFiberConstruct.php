<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\FiberTrace;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionFiber::__construct(Fiber $fiber) — VM (#4609, ext/reflection/php_reflection.c). */
final class ReflectionFiberConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('ReflectionFiber::__construct() expects a Fiber instance');
        }
        $receiver = ReflectionSupport::requireReflectionFiber($frame, $frame->calledArgs[0]);
        $fiberObject = FiberTrace::requireFiberObject(
            $frame->calledArgs[1],
            'ReflectionFiber::__construct',
            1
        );
        $wrapped = new Variable();
        $wrapped->object($fiberObject);
        $receiver->getProperty(ReflectionSupport::PROP_FIBER_TARGET)->copyFrom($wrapped);
        $receiver->constructed = true;
    }
}
