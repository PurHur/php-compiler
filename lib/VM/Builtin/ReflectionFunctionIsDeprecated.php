<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::isDeprecated() — VM (#9760, ext/reflection/php_reflection.c). */
final class ReflectionFunctionIsDeprecated extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isDeprecated');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        if (null === $frame->returnVar) {
            return;
        }
        if (ReflectionSupport::isReflectionInternalFunction($receiver)) {
            $frame->returnVar->bool(false);

            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $func = ReflectionSupport::resolveFunctionFromReflection($ctx, $receiver);
        $frame->returnVar->bool(null !== $func->deprecated);
    }
}
