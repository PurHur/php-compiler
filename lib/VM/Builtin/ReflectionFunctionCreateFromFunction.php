<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::createFromFunction(string $function) — VM (#6994, php_reflection.c). */
final class ReflectionFunctionCreateFromFunction extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('createFromFunction');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError(
                'ReflectionFunction::createFromFunction() expects exactly 1 argument, 0 given'
            );
        }
        $ctx = VmReflection::requireContext($frame);
        $name = VmReflection::stringArg(
            $frame->calledArgs[0],
            'ReflectionFunction::createFromFunction() function',
            0
        );
        $reflection = ReflectionSupport::reflectionFunctionFromFunctionName($ctx, $name);
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($reflection);
        }
    }
}
