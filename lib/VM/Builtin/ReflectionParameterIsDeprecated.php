<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionParameter::isDeprecated() — VM (#9768, ext/reflection/php_reflection.c). */
final class ReflectionParameterIsDeprecated extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isDeprecated');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionParameter($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::parameterIsDeprecated($ctx, $receiver));
        }
    }
}
