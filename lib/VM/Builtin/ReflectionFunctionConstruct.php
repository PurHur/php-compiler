<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmClosureCall;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::__construct($function) — VM (#3354, #3355). */
final class ReflectionFunctionConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('ReflectionFunction::__construct() expects a function name');
        }
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        $target = $frame->calledArgs[1];
        if (VmClosureCall::isClosure($target)) {
            $state = VmClosureCall::resolve($target);
            $receiver->reflectionClosureState = $state;
            $receiver->getProperty(ReflectionSupport::PROP_FUNC_NAME)->string($state->func->name);
        } else {
            $name = VmReflection::normalizeGlobalIntrospectionName(
                VmReflection::stringArg($target, 'ReflectionFunction::__construct() name', 1)
            );
            $func = ReflectionSupport::resolveFunctionForReflection($ctx, $name);
            $receiver->reflectionClosureState = null;
            $receiver->reflectionIsInternalFunction = $func instanceof \PHPCompiler\Func\Internal;
            $receiver->getProperty(ReflectionSupport::PROP_FUNC_NAME)->string($name);
        }
        $receiver->constructed = true;
    }
}
