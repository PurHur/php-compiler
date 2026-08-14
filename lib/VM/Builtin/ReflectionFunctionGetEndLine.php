<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::getEndLine() — VM (#22144, ext/reflection/php_reflection.c). */
final class ReflectionFunctionGetEndLine extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getEndLine');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionFunctionAbstract_getEndLine — 0 args (#30924)
        VmReflection::requireFunctionAbstractReceiverOnlyArgc($frame, 'getEndLine');
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        ReflectionSupport::returnFunctionEndLine(
            $frame->returnVar,
            ReflectionSupport::functionSourceLocation($ctx, $receiver),
            ReflectionSupport::isReflectionInternalFunction($receiver)
        );
    }
}
