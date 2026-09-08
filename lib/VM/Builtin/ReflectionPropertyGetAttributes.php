<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\AttributeRegistry;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\Lint\UnsupportedFeature;

/** ReflectionProperty::getAttributes() — VM read path (#4136). */
final class ReflectionPropertyGetAttributes extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getAttributes');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionProperty($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $property = ReflectionSupport::propertyNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            UnsupportedFeature::raise('reflection-property-unknown-class');
        }
        [$filter, $flags] = ReflectionSupport::getAttributesFilterArgs($frame, 'ReflectionProperty::getAttributes()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(
                AttributeRegistry::propertyAttributes($frame, $entry, strtolower($property), $filter, $flags)
            );
        }
    }
}
