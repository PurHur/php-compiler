<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFiber::getFiber(): Fiber — VM (#4609, ext/reflection/php_reflection.c). */
final class ReflectionFiberGetFiber extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getFiber');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionFiber($frame, $frame->calledArgs[0]);
        $target = $receiver->getProperty(ReflectionSupport::PROP_FIBER_TARGET)->resolveIndirect();
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($target);
        }
    }
}
