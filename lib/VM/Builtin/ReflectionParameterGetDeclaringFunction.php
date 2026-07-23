<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionParameter::getDeclaringFunction() — VM (#22408, ext/reflection/php_reflection.c). */
final class ReflectionParameterGetDeclaringFunction extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getDeclaringFunction');
    }

    public function execute(Frame $frame): void
    {
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionParameter($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->object(
                ReflectionSupport::parameterDeclaringFunction($ctx, $receiver)
            );
        }
    }
}
