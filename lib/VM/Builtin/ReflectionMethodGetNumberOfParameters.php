<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::getNumberOfParameters() — VM (#9723, ext/reflection/php_reflection.c). */
final class ReflectionMethodGetNumberOfParameters extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getNumberOfParameters');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $method = ReflectionSupport::methodNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionMethod refers to unknown class in this compiler build');
        }
        $methodLc = strtolower($method);
        $count = \count($entry->methodParameterMetadata[$methodLc] ?? []);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($count);
        }
    }
}
