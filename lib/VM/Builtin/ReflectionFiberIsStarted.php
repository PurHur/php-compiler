<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\FiberState;
use PHPCompiler\VM\FiberTrace;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFiber::isStarted(): bool — VM (#4609, ext/reflection/php_reflection.c). */
final class ReflectionFiberIsStarted extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isStarted');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionFiber($frame, $frame->calledArgs[0]);
        $fiber = FiberTrace::fiberStateFromReflection($receiver);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(FiberState::STATUS_INIT !== $fiber->status);
        }
    }
}
