<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

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
            throw new \LogicException('ReflectionProperty refers to unknown class in this compiler build');
        }
        $propLc = strtolower($property);
        $all = $entry->propertyAttributeNames[$propLc] ?? [];
        $allEntries = $entry->propertyAttributeEntries[$propLc] ?? [];
        $filter = null;
        if (isset($frame->calledArgs[1])) {
            $filter = VmReflection::stringArg($frame->calledArgs[1], 'ReflectionProperty::getAttributes() name');
        }
        $entries = ReflectionSupport::filterEntriesByName($allEntries, $filter);
        if ([] !== $entries) {
            $out = ReflectionSupport::attributesArrayFromEntries($frame, $entries);
        } else {
            $names = ReflectionSupport::filterByName($all, $filter);
            $out = ReflectionSupport::attributesArray($frame, $names);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($out);
        }
    }
}
