<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::invoke(...$args) — VM (#7039, #24949, php_reflection.c). */
final class ReflectionFunctionInvoke extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('invoke');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError(
                'ReflectionFunction::invoke() expects at least 0 arguments, '.($argc - 1).' given'
            );
        }
        $reflection = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        [$paramNames, $variadicIndex, $functionName] = ReflectionSupport::functionInvokeParamMetadata(
            $ctx,
            $reflection
        );
        $invokeArgs = ReflectionSupport::invokeTrailingArgsFromCalledArgs(
            $frame,
            1,
            $paramNames,
            $variadicIndex,
            $functionName
        );
        $vm = VM::running();
        if (null === $vm) {
            throw new \LogicException('ReflectionFunction::invoke() requires active VM');
        }
        $result = ReflectionSupport::invokeReflectionFunction($vm, $frame, $reflection, $invokeArgs);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
    }
}
