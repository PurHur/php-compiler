<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::__toString() — VM (#22379, ext/reflection/php_reflection.c _function_string). */
final class ReflectionFunctionToString extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__toString');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $ctx = VmReflection::requireContext($frame);
            $frame->returnVar->string(ReflectionSupport::functionReflectionToString($ctx, $receiver));
        }
    }
}
