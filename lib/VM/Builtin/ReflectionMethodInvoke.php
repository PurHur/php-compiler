<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::invoke($object, ...$args) — VM (#7117, #24949, php_reflection.c). */
final class ReflectionMethodInvoke extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('invoke');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(
                'ReflectionMethod::invoke() expects at least 1 argument, '.($argc - 1).' given'
            );
        }
        $reflection = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        $objectArg = $frame->calledArgs[1];
        $ctx = VmReflection::requireContext($frame);
        [$paramNames, $variadicIndex, $functionName] = ReflectionSupport::methodInvokeParamMetadata(
            $ctx,
            $reflection
        );
        $invokeArgs = ReflectionSupport::invokeTrailingArgsFromCalledArgs(
            $frame,
            2,
            $paramNames,
            $variadicIndex,
            $functionName
        );
        $vm = VM::running();
        if (null === $vm) {
            throw new \LogicException('ReflectionMethod::invoke() requires active VM');
        }
        $result = ReflectionSupport::invokeReflectedMethod($vm, $frame, $reflection, $objectArg, $invokeArgs);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
    }
}
