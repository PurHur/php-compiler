<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionClass::__toString() — VM (#22379, ext/reflection/php_reflection.c _class_string). */
final class ReflectionClassToString extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__toString');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionClass($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $ctx = VmReflection::requireContext($frame);
            $frame->returnVar->string(ReflectionSupport::classReflectionToString($ctx, $receiver));
        }
    }
}
