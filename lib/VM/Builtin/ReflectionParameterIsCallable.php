<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionParameter::isCallable() — VM (#22408, ext/reflection/php_reflection.c). */
final class ReflectionParameterIsCallable extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isCallable');
    }

    public function execute(Frame $frame): void
    {
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionParameter($frame, $frame->calledArgs[0]);
        ReflectionSupport::emitLegacyParameterTypeApiDeprecation($frame, 'isCallable');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::parameterIsCallable($ctx, $receiver));
        }
    }
}
