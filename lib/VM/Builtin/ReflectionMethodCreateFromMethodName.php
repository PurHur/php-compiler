<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::createFromMethodName(string $method) — VM (#7038, php_reflection.c). */
final class ReflectionMethodCreateFromMethodName extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('createFromMethodName');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError(
                'ReflectionMethod::createFromMethodName() expects exactly 1 argument, 0 given'
            );
        }
        $ctx = VmReflection::requireContext($frame);
        $method = VmReflection::stringArg(
            $frame->calledArgs[0],
            'ReflectionMethod::createFromMethodName() method',
            0
        );
        $reflection = ReflectionSupport::reflectionMethodFromMethodName($ctx, $method);
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($reflection);
        }
    }
}
