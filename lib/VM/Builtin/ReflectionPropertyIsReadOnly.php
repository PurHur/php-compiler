<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\Lint\UnsupportedFeature;

/** ReflectionProperty::isReadOnly() — VM (#7187/#23544, ext/reflection/php_reflection.c). */
final class ReflectionPropertyIsReadOnly extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isReadOnly');
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
        $meta = VmReflection::findClassProperty($entry, $property, $ctx);
        if (null === $meta) {
            // Static properties are never readonly (php_reflection.c / ZEND_ACC_READONLY).
            // findClassProperty walks instance props only — do not throw for existing statics (#23544).
            if (null !== VmReflection::findStaticPropertyKey($entry, $property, $ctx)) {
                if (null !== $frame->returnVar) {
                    $frame->returnVar->bool(false);
                }

                return;
            }
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::propertyNotFoundMessage($className, $property)
            );
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($meta->readonly);
        }
    }
}
