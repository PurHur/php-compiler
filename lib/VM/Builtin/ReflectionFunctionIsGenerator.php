<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::isGenerator() — VM (#17505, ext/reflection/php_reflection.c). */
final class ReflectionFunctionIsGenerator extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isGenerator');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $ctx = VmReflection::requireContext($frame);
            $frame->returnVar->bool(ReflectionSupport::isReflectionFunctionGenerator($ctx, $receiver));
        }
    }
}
