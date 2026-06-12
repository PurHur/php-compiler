<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\PropertyVisibility;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/**
 * ReflectionProperty::getAsymmetricVisibility() — VM (#5060, #6772, ext/reflection/php_reflection.c).
 *
 * php-src: reflection_property_get_asymmetric_visibility — returns null when symmetric,
 * otherwise ['get' => ReflectionProperty::IS_*, 'set' => ReflectionProperty::IS_*].
 */
final class ReflectionPropertyGetAsymmetricVisibility extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getAsymmetricVisibility');
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
        $meta = VmReflection::propertyVisibilityMeta($entry, $property, $ctx);
        if (null === $meta) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::propertyNotFoundMessage($className, $property)
            );
        }

        $effectiveSet = PropertyVisibility::effectiveSetVisibility($meta['visibility'], $meta['setVisibility']);
        $effectiveGet = PropertyVisibility::effectiveGetVisibility($meta['visibility'], $meta['getVisibility']);

        if ($effectiveSet === $effectiveGet) {
            $frame->returnVar->null();

            return;
        }

        $frame->returnVar->newArray();
        $ht = $frame->returnVar->toArray();

        $getVal = new Variable();
        $getVal->int(VmReflection::visibilityToReflectionBitmask($effectiveGet));
        $ht->add('get', $getVal);

        $setVal = new Variable();
        $setVal->int(VmReflection::visibilityToReflectionBitmask($effectiveSet));
        $ht->add('set', $setVal);
    }
}
