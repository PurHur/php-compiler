<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\Lint\UnsupportedFeature;

/** ReflectionProperty::isDefaultValueAvailable() — VM (#11442, PHP 8.4 ext/reflection/php_reflection.c). */
final class ReflectionPropertyIsDefaultValueAvailable extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isDefaultValueAvailable');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($this->resolveAvailable($frame));
    }

    private function resolveAvailable(Frame $frame): bool
    {
        $receiver = ReflectionSupport::requireReflectionProperty($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            UnsupportedFeature::raise('reflection-property-unknown-class');
        }
        $property = ReflectionSupport::propertyNameFromReflection($receiver);
        $staticKey = VmReflection::findStaticPropertyKey($entry, $property, $ctx);
        if (null !== $staticKey) {
            return VmReflection::staticPropertyHasDefaultValue($entry->staticProperties[$staticKey]);
        }
        $meta = VmReflection::findClassProperty($entry, $property, $ctx);
        if (null === $meta) {
            ReflectionSupport::throwReflectionException(
                sprintf('Property %s::$%s does not exist', $className, $property)
            );
        }

        return VmReflection::propertyDefaultValueIsAvailable($meta);
    }
}
