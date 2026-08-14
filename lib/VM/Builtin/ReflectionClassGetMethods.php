<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
/** ReflectionClass::getMethods() — VM (#3815). */
final class ReflectionClassGetMethods extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getMethods');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionClass($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionClass refers to unknown class in this compiler build');
        }
        $filter = VmReflection::optionalReflectionFilterArg($frame, 1, 'ReflectionClass::getMethods');
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(
                VmReflection::reflectionMethodsArray($ctx, $entry, $entry->name, $filter)
            );
        }
    }
}
