<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::returnsReference() — VM (#22171, ext/reflection/php_reflection.c). */
final class ReflectionMethodReturnsReference extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('returnsReference');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionFunctionAbstract_returnsReference — 0 args (#30924)
        VmReflection::requireFunctionAbstractReceiverOnlyArgc($frame, 'returnsReference');
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::methodReturnsReference($ctx, $receiver));
        }
    }
}
