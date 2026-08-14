<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::isVariadic() — VM (#18228, ext/reflection/php_reflection.c). */
final class ReflectionMethodIsVariadic extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isVariadic');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionFunctionAbstract_isVariadic — 0 args (#30924)
        VmReflection::requireFunctionAbstractReceiverOnlyArgc($frame, 'isVariadic');
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::isReflectionMethodVariadic($ctx, $receiver));
        }
    }
}
