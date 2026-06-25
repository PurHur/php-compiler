<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionProperty::hasDefaultValue() — VM (#11442, ext/reflection/php_reflection.c). */
final class ReflectionPropertyHasDefaultValue extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('hasDefaultValue');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($this->resolveHasDefault($frame));
    }

    private function resolveHasDefault(Frame $frame): bool
    {
        $receiver = ReflectionSupport::requireReflectionProperty($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionProperty refers to unknown class in this compiler build');
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

        return VmReflection::propertyHasDefaultValue($meta);
    }
}
