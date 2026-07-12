<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\BuiltinParamNames;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\Func\PHP as PhpFunc;
use PHPCompiler\VM\ParamArgumentCountError;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::getNumberOfRequiredParameters() — VM (#18325, ext/reflection/php_reflection.c). */
final class ReflectionFunctionGetNumberOfRequiredParameters extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getNumberOfRequiredParameters');
    }

    public function execute(Frame $frame): void
    {
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        $funcName = ReflectionSupport::functionNameFromReflection($receiver);
        if (ReflectionSupport::isReflectionInternalFunction($receiver)) {
            $count = BuiltinParamNames::requiredParamCountForInternalFunction($funcName) ?? 0;
        } else {
            $func = ReflectionSupport::resolveFunctionFromReflection($ctx, $receiver);
            $count = self::requiredParameterCountFromBlock($func->block);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($count);
        }
    }

    private static function requiredParameterCountFromBlock(\PHPCompiler\Block $block): int
    {
        $required = 0;
        for ($i = 0, $n = \count($block->paramNames); $i < $n; ++$i) {
            if (null !== $block->variadicParamIndex && $block->variadicParamIndex === $i) {
                break;
            }
            if (ParamArgumentCountError::parameterHasDefault($block, $i)) {
                break;
            }
            ++$required;
        }

        return $required;
    }
}
