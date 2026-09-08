<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\Lint\UnsupportedFeature;

/** ReflectionProperty::isStatic() — VM (#22143, ext/reflection/php_reflection.c). */
final class ReflectionPropertyIsStatic extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isStatic');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionProperty($frame, $frame->calledArgs[0]);
        if (ReflectionSupport::isDynamicReflectionProperty($receiver)) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            UnsupportedFeature::raise('reflection-property-unknown-class');
        }
        $property = ReflectionSupport::propertyNameFromReflection($receiver);
        if (VmReflection::isEnumReflectionPseudoProperty($entry, $property)) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmReflection::propertyIsStatic($entry, $property, $ctx));
        }
    }
}
