<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::invokeArgs(array $args) — VM (#22088, #23388, php_reflection.c). */
final class ReflectionFunctionInvokeArgs extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('invokeArgs');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'ReflectionFunction::invokeArgs() expects exactly 1 argument, '.($argc - 1).' given'
            );
        }
        $reflection = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        [$paramNames, $variadicIndex, $functionName] = ReflectionSupport::functionInvokeParamMetadata(
            $ctx,
            $reflection
        );
        $invokeArgs = ReflectionSupport::invokeArgsFromArray(
            $frame->calledArgs[1],
            'ReflectionFunction::invokeArgs',
            1,
            $paramNames,
            $variadicIndex,
            $functionName
        );
        $vm = VM::running();
        if (null === $vm) {
            throw new \LogicException('ReflectionFunction::invokeArgs() requires active VM');
        }
        $result = ReflectionSupport::invokeReflectionFunction($vm, $frame, $reflection, $invokeArgs);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
    }
}
