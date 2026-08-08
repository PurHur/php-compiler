<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmClosureCall;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::__construct($function) — VM (#3354, #3355, #28939). */
final class ReflectionFunctionConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs) - 1;
        if ($argc !== 1) {
            ReflectionSupport::throwConstructArgumentCountError('ReflectionFunction', 1, $argc);
        }
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        $target = $frame->calledArgs[1];
        if (VmClosureCall::isClosure($target)) {
            $state = VmClosureCall::resolve($target);
            $receiver->reflectionClosureState = $state;
            // fromCallable wrappers: report underlying name (strlen / createFromFormat), not {closure} (#22330).
            $receiver->getProperty(ReflectionSupport::PROP_REFLECTION_FUNCTION_NAME)->string(
                ReflectionSupport::displayNameForClosureState($state)
            );
        } else {
            $name = VmReflection::normalizeGlobalIntrospectionName(
                VmReflection::stringArg($target, 'ReflectionFunction::__construct() function', 1)
            );
            $func = ReflectionSupport::resolveFunctionForReflection($ctx, $name);
            $receiver->reflectionClosureState = null;
            $receiver->reflectionIsInternalFunction = $func instanceof \PHPCompiler\Func\Internal;
            $receiver->getProperty(ReflectionSupport::PROP_REFLECTION_FUNCTION_NAME)->string($name);
        }
        $receiver->constructed = true;
    }
}
