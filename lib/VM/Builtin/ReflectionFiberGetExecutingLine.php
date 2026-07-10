<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\FiberTrace;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFiber::getExecutingLine(): int — VM (#4609, ext/reflection/php_reflection.c). */
final class ReflectionFiberGetExecutingLine extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getExecutingLine');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionFiber($frame, $frame->calledArgs[0]);
        $fiber = FiberTrace::fiberStateFromReflection($receiver);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(FiberTrace::executingLine($fiber));
        }
    }
}
