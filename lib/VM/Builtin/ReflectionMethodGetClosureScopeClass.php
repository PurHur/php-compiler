<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::getClosureScopeClass() — VM (#14614, ext/reflection/php_reflection.c). */
final class ReflectionMethodGetClosureScopeClass extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getClosureScopeClass');
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
        $scope = ReflectionSupport::closureDefinitionScopeClassName($state);
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
