<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::getNumberOfRequiredParameters() — VM (#18325, #25559, ext/reflection/php_reflection.c). */
final class ReflectionFunctionGetNumberOfRequiredParameters extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getNumberOfRequiredParameters');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionFunctionAbstract_getNumberOfRequiredParameters — 0 args (#30924)
        VmReflection::requireFunctionAbstractReceiverOnlyArgc($frame, 'getNumberOfRequiredParameters');
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        $count = ReflectionSupport::functionNumberOfRequiredParameters($ctx, $receiver);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($count);
        }
    }
}
