<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionPropertyTypeSupport;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\Lint\UnsupportedFeature;

/** ReflectionProperty::hasType() — VM (#22063, ext/reflection/php_reflection.c). */
final class ReflectionPropertyHasType extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('hasType');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionProperty($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            UnsupportedFeature::raise('reflection-property-unknown-class');
        }
        $property = ReflectionSupport::propertyNameFromReflection($receiver);
        $meta = VmReflection::findClassProperty($entry, $property, $ctx);
        if (null === $meta && null === VmReflection::findStaticPropertyKey($entry, $property, $ctx)) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::propertyNotFoundMessage($className, $property)
            );
        }
        if (null !== $frame->returnVar) {
            // php-src: prop->type presence — same source as getType() (#22063, #22481).
            $declared = ReflectionPropertyTypeSupport::declaredType($entry, $property, $meta, $ctx);
            $frame->returnVar->bool(null !== $declared);
        }
    }
}
