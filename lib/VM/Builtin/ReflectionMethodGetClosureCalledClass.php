<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::getClosureCalledClass() — VM (#22166, ext/reflection/php_reflection.c). */
final class ReflectionMethodGetClosureCalledClass extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getClosureCalledClass');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        if (null === $frame->returnVar) {
            return;
        }
        $state = $receiver->reflectionClosureState;
        if (null === $state) {
            $frame->returnVar->null();

            return;
        }
        $scope = ReflectionSupport::closureCalledScopeClassName($state);
        if (null === $scope) {
            $frame->returnVar->null();

            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $frame->returnVar->object(
            ReflectionSupport::newReflectionClassObjectForName($ctx, $scope)
        );
    }
}
