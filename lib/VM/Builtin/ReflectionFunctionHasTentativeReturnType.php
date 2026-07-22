<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::hasTentativeReturnType() — VM (#22169, ext/reflection/php_reflection.c). */
final class ReflectionFunctionHasTentativeReturnType extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('hasTentativeReturnType');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        $frame->returnVar->bool(ReflectionSupport::reflectedFunctionHasTentativeReturnType($receiver));
    }
}
