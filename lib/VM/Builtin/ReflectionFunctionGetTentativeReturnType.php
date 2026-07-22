<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\ReflectionTypeSupport;

/** ReflectionFunction::getTentativeReturnType() — VM (#22068, ext/reflection/php_reflection.c). */
final class ReflectionFunctionGetTentativeReturnType extends VmClassMethod
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
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        $declared = ReflectionSupport::reflectedFunctionTentativeReturnType($receiver);
        if (null === $declared) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->copyFrom(ReflectionTypeSupport::buildTypeVariable($ctx, $declared));
    }
}
