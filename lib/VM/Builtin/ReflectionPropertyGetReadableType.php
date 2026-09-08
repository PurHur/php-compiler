<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionPropertyTypeSupport;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\ReflectionTypeSupport;
use PHPCompiler\Lint\UnsupportedFeature;

/**
 * ReflectionProperty::getReadableType() — phantom vs php-src (#28532, re-#7053).
 * Class kept on the spine; never registered under php-src-strict profiles.
 */
final class ReflectionPropertyGetReadableType extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getReadableType');
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
            $declared = ReflectionPropertyTypeSupport::readableType($entry, $property, $meta, $ctx);
            if (null === $declared) {
                $frame->returnVar->null();
            } else {
                $frame->returnVar->copyFrom(ReflectionTypeSupport::buildTypeVariable($ctx, $declared));
            }
        }
    }
}
