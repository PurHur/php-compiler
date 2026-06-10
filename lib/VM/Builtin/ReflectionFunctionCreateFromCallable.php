<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::createFromCallable(callable $function) — VM (#7039, php_reflection.c). */
final class ReflectionFunctionCreateFromCallable extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('createFromCallable');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError(
                'ReflectionFunction::createFromCallable() expects exactly 1 argument, 0 given'
            );
        }
        $ctx = VmReflection::requireContext($frame);
        $reflection = ReflectionSupport::reflectionFunctionFromCallable($ctx, $frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($reflection);
        }
    }
}
