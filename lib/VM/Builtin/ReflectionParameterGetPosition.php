<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionParameter::getPosition() — VM (#22285, ext/reflection/php_reflection.c). */
final class ReflectionParameterGetPosition extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getPosition');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionParameter($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(ReflectionSupport::parameterIndexForReflection($receiver));
        }
    }
}
