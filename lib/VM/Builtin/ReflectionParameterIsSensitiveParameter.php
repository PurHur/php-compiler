<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionParameter::isSensitiveParameter() — #[\SensitiveParameter] probe (#16130, ext/reflection/php_reflection.c). */
final class ReflectionParameterIsSensitiveParameter extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isSensitiveParameter');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionParameter($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::parameterIsSensitive($ctx, $receiver));
        }
    }
}
