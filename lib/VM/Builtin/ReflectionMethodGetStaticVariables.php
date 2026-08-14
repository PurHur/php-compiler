<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::getStaticVariables() — VM (#14166, ext/reflection/php_reflection.c). */
final class ReflectionMethodGetStaticVariables extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getStaticVariables');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionFunctionAbstract_getStaticVariables — 0 args (#30924)
        VmReflection::requireFunctionAbstractReceiverOnlyArgc($frame, 'getStaticVariables');
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        ReflectionSupport::returnStaticVariablesFromMethodReflection($ctx, $receiver, $frame->returnVar);
    }
}
