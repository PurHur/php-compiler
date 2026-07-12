<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::getTentativeReturnType() — VM (#18226, ext/reflection/php_reflection.c). */
final class ReflectionMethodGetTentativeReturnType extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getTentativeReturnType');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        $frame->returnVar->copyFrom(
            ReflectionSupport::reflectedMethodGetTentativeReturnTypeVariable($ctx, $receiver)
        );
    }
}
