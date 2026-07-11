<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::isGenerator() — VM (#17505, ext/reflection/php_reflection.c). */
final class ReflectionMethodIsGenerator extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isGenerator');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $ctx = VmReflection::requireContext($frame);
            $frame->returnVar->bool(ReflectionSupport::isReflectionMethodGenerator($ctx, $receiver));
        }
    }
}
