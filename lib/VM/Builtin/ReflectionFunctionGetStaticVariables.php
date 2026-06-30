<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::getStaticVariables() — VM (#14166, ext/reflection/php_reflection.c). */
final class ReflectionFunctionGetStaticVariables extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getStaticVariables');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        ReflectionSupport::returnStaticVariablesFromFunctionReflection($ctx, $receiver, $frame->returnVar);
    }
}
