<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionParameter::getDefaultValue() — VM (#4385, ext/reflection/php_reflection.c). */
final class ReflectionParameterGetDefaultValue extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getDefaultValue');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionParameter($frame, $frame->calledArgs[0]);
        $block = ReflectionSupport::resolveParameterBlock($ctx, $receiver);
        $index = ReflectionSupport::parameterIndexForReflection($receiver);
        if (!VmReflection::parameterDefaultValueIsAvailable($block, $index)) {
            ReflectionSupport::throwReflectionException(
                'Parameter '.ReflectionSupport::paramNameFromReflection($receiver)
                .' does not have a default value'
            );
        }
        if (!VmReflection::copyParameterDefaultValue($frame->returnVar, $block, $index, $ctx)) {
            $frame->returnVar->null();
        }
    }
}
