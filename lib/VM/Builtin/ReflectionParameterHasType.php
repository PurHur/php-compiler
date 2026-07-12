<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionParameter::hasType() — VM (#4385, ext/reflection/php_reflection.c). */
final class ReflectionParameterHasType extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('hasType');
    }

    public function execute(Frame $frame): void
    {
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionParameter($frame, $frame->calledArgs[0]);
        $block = ReflectionSupport::resolveParameterBlock($ctx, $receiver);
        $index = ReflectionSupport::parameterIndexForReflection($receiver);
        $slot = ReflectionSupport::parameterScopeSlot($block, $index);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(null !== $slot && isset($block->paramDeclaredTypes[$slot]));
        }
    }
}
