<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionParameter::allowsNull() — VM (#18073, ext/reflection/php_reflection.c). */
final class ReflectionParameterAllowsNull extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('allowsNull');
    }

    public function execute(Frame $frame): void
    {
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionParameter($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::parameterAllowsNull($ctx, $receiver));
        }
    }
}
