<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionParameter::getDefaultValueConstantName() — VM (#22026, ext/reflection/php_reflection.c). */
final class ReflectionParameterGetDefaultValueConstantName extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getDefaultValueConstantName');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionParameter($frame, $frame->calledArgs[0]);
        $name = ReflectionSupport::parameterDefaultValueConstantNameForReflection($ctx, $receiver);
        if (null === $name) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->string($name);
    }
}
