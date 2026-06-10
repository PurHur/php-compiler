<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionFunction::invoke(...$args) — VM (#7039, php_reflection.c). */
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
        $invokeArgs = [];
        for ($i = 1; $i < $argc; ++$i) {
            $copy = new Variable();
            $copy->copyFrom($frame->calledArgs[$i]->resolveIndirect());
            $invokeArgs[] = $copy;
        }
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
