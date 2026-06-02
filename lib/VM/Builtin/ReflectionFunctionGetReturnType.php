<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\ReflectionTypeSupport;
use PHPCompiler\VM\Variable;

/** ReflectionFunction::getReturnType() — VM (#3355). */
final class ReflectionFunctionGetReturnType extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getReturnType');
    }

    public function execute(Frame $frame): void
    {
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        $func = ReflectionSupport::resolveFunctionFromReflection($ctx, $receiver);
        if (null !== $frame->returnVar) {
            $declared = $func->block->returnDeclaredType;
            if (null === $declared) {
                $frame->returnVar->null();
            } else {
                $frame->returnVar->copyFrom(ReflectionTypeSupport::buildTypeVariable($ctx, $declared));
            }
        }
    }
}
