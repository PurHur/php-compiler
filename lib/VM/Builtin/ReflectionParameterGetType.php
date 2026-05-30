<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\ReflectionTypeSupport;
use PHPCompiler\VM\Variable;

/** ReflectionParameter::getType() — VM (#3355). */
final class ReflectionParameterGetType extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getType');
    }

    public function execute(Frame $frame): void
    {
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionParameter($frame, $frame->calledArgs[0]);
        $func = ReflectionSupport::resolveUserFunction(
            $ctx,
            ReflectionSupport::functionNameFromReflection($receiver)
        );
        $index = ReflectionSupport::paramIndexFromReflection($receiver);
        if (null !== $frame->returnVar) {
            $declared = $func->block->paramDeclaredTypes[$index] ?? null;
            if (null === $declared) {
                $frame->returnVar->null();
            } else {
                $frame->returnVar->copyFrom(ReflectionTypeSupport::buildTypeVariable($ctx, $declared));
            }
        }
    }
}
