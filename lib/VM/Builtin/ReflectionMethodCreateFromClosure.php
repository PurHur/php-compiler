<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionMethod::createFromClosure(Closure $closure, ?string $scope, ?string $name) — VM (#7039). */
final class ReflectionMethodCreateFromClosure extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('createFromClosure');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError(
                'ReflectionMethod::createFromClosure() expects at least 1 argument, 0 given'
            );
        }
        $ctx = VmReflection::requireContext($frame);
        $scopeArg = $frame->calledArgs[1] ?? null;
        $nameArg = $frame->calledArgs[2] ?? null;
        if (null === $scopeArg) {
            $null = new Variable(Variable::TYPE_NULL);
            $null->null();
            $scopeArg = $null;
        }
        if (null === $nameArg) {
            $null = new Variable(Variable::TYPE_NULL);
            $null->null();
            $nameArg = $null;
        }
        $reflection = ReflectionSupport::reflectionMethodFromClosure(
            $ctx,
            $frame->calledArgs[0],
            $scopeArg,
            $nameArg
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($reflection);
        }
    }
}
