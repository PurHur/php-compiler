<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionClass::hasProperty() — VM (#6301, ext/reflection/php_reflection.c). */
final class ReflectionClassHasProperty extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('hasProperty');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('ReflectionClass::hasProperty() expects a property name');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $receiver = ReflectionSupport::requireReflectionClass($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionClass refers to unknown class in this compiler build');
        }
        $property = VmReflection::stringArg($frame->calledArgs[1], 'ReflectionClass::hasProperty() name', 1);
        $filter = VmReflection::optionalReflectionFilterArg($frame, 2, 'ReflectionClass::hasProperty');
        $frame->returnVar->bool(
            VmReflection::classHasPropertyForReflection($entry, $ctx, $property, $filter)
        );
    }
}
