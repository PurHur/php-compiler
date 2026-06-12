<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::getClosureUsedVariables() — VM (#6649, ext/reflection/php_reflection.c). */
final class ReflectionFunctionGetClosureUsedVariables extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getClosureUsedVariables');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        if (null === $frame->returnVar) {
            return;
        }
        $state = $receiver->reflectionClosureState;
        if (null === $state) {
            $returnVar = $frame->returnVar;
            $returnVar->newArray();

            return;
        }
        ReflectionSupport::returnClosureUsedVariables($frame->returnVar, $state);
    }
}
