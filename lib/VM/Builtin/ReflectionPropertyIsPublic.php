<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\MethodVisibility;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\Lint\UnsupportedFeature;

/** ReflectionProperty::isPublic() — VM (#4395). */
final class ReflectionPropertyIsPublic extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isPublic');
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
        if (VmReflection::isEnumReflectionPseudoProperty($entry, $property)) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(true);
            }

            return;
        }
        if (ReflectionSupport::isDynamicReflectionProperty($receiver)) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(true);
            }

            return;
        }
        $meta = VmReflection::propertyVisibilityMeta($entry, $property, $ctx);
        if (null === $meta) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::propertyNotFoundMessage($className, $property)
            );
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(MethodVisibility::isPublic($meta['visibility']));
        }
    }
}
