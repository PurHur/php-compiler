<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/**
 * ReflectionProperty::getModifiers() — VM (#22143, #22341, #28137, ext/reflection/php_reflection.c).
 * Returns ReflectionProperty::IS_* bitmask (visibility | *_SET | static | readonly | final).
 */
final class ReflectionPropertyGetModifiers extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getModifiers');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionProperty($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionProperty refers to unknown class in this compiler build');
        }
        $property = ReflectionSupport::propertyNameFromReflection($receiver);
        $isDynamic = ReflectionSupport::isDynamicReflectionProperty($receiver);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(
                VmReflection::propertyReflectionModifiers($entry, $property, $ctx, $isDynamic)
            );
        }
    }
}
