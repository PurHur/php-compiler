<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionParameter::isNamed() — VM (#18073, ext/reflection/php_reflection.c). */
final class ReflectionParameterIsNamed extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isNamed');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionParameter($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::parameterIsNamed($receiver));
        }
    }
}
