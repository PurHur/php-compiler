<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\FiberState;
use PHPCompiler\VM\Variable;

/** ReflectionFiber::getExecutingFiber(): ?ReflectionFiber — VM (#6793). */
final class ReflectionFiberGetExecutingFiber extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getExecutingFiber');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            $frame->returnVar->null();

            return;
        }
        $current = $ctx->currentFiber;
        if (null === $current || FiberState::STATUS_TERMINATED === $current->status) {
            $frame->returnVar->null();

            return;
        }
        $wrapper = VmReflection::newReflectionFiber($ctx, $current->object);
        $out = new Variable(Variable::TYPE_OBJECT);
        $out->object($wrapper);
        $frame->returnVar->copyFrom($out);
    }
}
