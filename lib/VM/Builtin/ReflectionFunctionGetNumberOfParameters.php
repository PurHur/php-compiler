<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::getNumberOfParameters() — VM (#9723, #25559, ext/reflection/php_reflection.c). */
final class ReflectionFunctionGetNumberOfParameters extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getNumberOfParameters');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionFunctionAbstract_getNumberOfParameters — 0 args (#30924)
        VmReflection::requireFunctionAbstractReceiverOnlyArgc($frame, 'getNumberOfParameters');
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        $count = ReflectionSupport::functionNumberOfParameters($ctx, $receiver);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($count);
        }
    }
}
