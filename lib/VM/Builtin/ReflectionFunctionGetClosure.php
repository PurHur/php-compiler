<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\ClosureSupport;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionFunction::getClosure() — VM (#12905, ext/reflection/php_reflection.c). */
final class ReflectionFunctionGetClosure extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getClosure');
    }

    public function execute(Frame $frame): void
    {
        $reflection = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $stored = $reflection->reflectionClosureState;
        if (null !== $stored) {
            $state = $stored->isUserClosure() ? $stored->cloneForBind() : $stored;
            $out = new Variable(Variable::TYPE_OBJECT);
            $out->object(ClosureSupport::wrapState($ctx, $state));
            $frame->returnVar->copyFrom($out);

            return;
        }
        $func = ReflectionSupport::resolveFunctionForReflection(
            $ctx,
            ReflectionSupport::functionNameFromReflection($reflection)
        );
        $out = new Variable(Variable::TYPE_OBJECT);
        $out->object(ClosureSupport::wrapState($ctx, ClosureState::fromWrappedFunc($func)));
        $frame->returnVar->copyFrom($out);
    }
}
