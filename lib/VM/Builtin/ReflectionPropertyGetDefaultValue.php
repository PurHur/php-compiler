<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionProperty::getDefaultValue() — VM (#11239, ext/reflection/php_reflection.c). */
final class ReflectionPropertyGetDefaultValue extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getDefaultValue');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
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
            if (!VmReflection::copyStaticPropertyDefaultValue(
                $frame->returnVar,
                $entry->staticProperties[$staticKey]
            )) {
                $frame->returnVar->null();
            }

            return;
        }
        $meta = VmReflection::findClassProperty($entry, $property, $ctx);
        if (null === $meta) {
            ReflectionSupport::throwReflectionException(
                sprintf('Property %s::$%s does not exist', $className, $property)
            );
        }
        if (!VmReflection::copyPropertyDefaultValue($frame->returnVar, $meta, $ctx)) {
            $frame->returnVar->null();
        }
    }
}
