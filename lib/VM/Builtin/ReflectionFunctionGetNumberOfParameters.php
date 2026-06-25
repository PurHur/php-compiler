<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\BuiltinParamNames;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::getNumberOfParameters() — VM (#9723, ext/reflection/php_reflection.c). */
final class ReflectionFunctionGetNumberOfParameters extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getNumberOfParameters');
    }

    public function execute(Frame $frame): void
    {
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        $funcName = ReflectionSupport::functionNameFromReflection($receiver);
        if (ReflectionSupport::isReflectionInternalFunction($receiver)) {
            $count = BuiltinParamNames::paramCountForInternalFunction($funcName) ?? 0;
        } else {
            $func = ReflectionSupport::resolveFunctionFromReflection($ctx, $receiver);
            $count = \count($func->block->paramNames);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($count);
        }
    }
}
