<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::getNumberOfRequiredParameters() — VM (#18325, ext/reflection/php_reflection.c). */
final class ReflectionMethodGetNumberOfRequiredParameters extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getNumberOfRequiredParameters');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionFunctionAbstract_getNumberOfRequiredParameters — 0 args (#30924)
        VmReflection::requireFunctionAbstractReceiverOnlyArgc($frame, 'getNumberOfRequiredParameters');
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $method = ReflectionSupport::methodNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionMethod refers to unknown class in this compiler build');
        }
        $count = ReflectionSupport::methodNumberOfRequiredParameters($entry, $method);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($count);
        }
    }
}
