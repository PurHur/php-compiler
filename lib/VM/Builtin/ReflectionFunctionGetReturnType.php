<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\ReflectionTypeSupport;
use PHPCompiler\VM\Variable;

/** ReflectionFunction::getReturnType() — VM (#3355, #22068; ext/reflection/php_reflection.c). */
final class ReflectionFunctionGetReturnType extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getReturnType');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        if (ReflectionSupport::isReflectionInternalFunction($receiver)) {
            $declared = ReflectionSupport::reflectedFunctionInternalReturnType($receiver);
            if (null === $declared) {
                $frame->returnVar->null();
            } else {
                $frame->returnVar->copyFrom(ReflectionTypeSupport::buildTypeVariable($ctx, $declared));
            }

            return;
        }
        $func = ReflectionSupport::resolveFunctionFromReflection($ctx, $receiver);
        $declared = $func->block->returnDeclaredType;
        if (null === $declared || !ReflectionSupport::hasDeclaredReturnType($declared)) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->copyFrom(ReflectionTypeSupport::buildTypeVariable($ctx, $declared));
        }
    }
}
