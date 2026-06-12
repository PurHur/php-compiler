<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::getClosureScopeClass() — VM (#6649, ext/reflection/php_reflection.c). */
final class ReflectionFunctionGetClosureScopeClass extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getClosureScopeClass');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
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
