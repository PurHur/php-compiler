<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::getFileName() — VM (#22144, ext/reflection/php_reflection.c). */
final class ReflectionFunctionGetFileName extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getFileName');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionFunctionAbstract_getFileName — 0 args (#30924)
        VmReflection::requireFunctionAbstractReceiverOnlyArgc($frame, 'getFileName');
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        ReflectionSupport::returnFunctionFileName(
            $frame->returnVar,
            ReflectionSupport::functionSourceLocation($ctx, $receiver),
            ReflectionSupport::isReflectionInternalFunction($receiver)
        );
    }
}
