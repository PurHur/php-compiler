<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::isClosure() — VM (#22173, ext/reflection/php_reflection.c). */
final class ReflectionMethodIsClosure extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isClosure');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::isReflectionClosure($receiver));
        }
    }
}
