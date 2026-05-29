<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Compiler\AttributeEntry;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionClass::getAttributes() — VM read path (#1936). */
final class ReflectionClassGetAttributes extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getAttributes');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionClass($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionClass refers to unknown class in this compiler build');
        }
        $filter = null;
        if (isset($frame->calledArgs[1])) {
            $filter = VmReflection::stringArg($frame->calledArgs[1], 'ReflectionClass::getAttributes() name');
        }
        $entries = $entry->attributeEntries;
        if ([] === $entries && [] !== $entry->attributeNames) {
            foreach ($entry->attributeNames as $name) {
                $entries[] = new AttributeEntry($name);
            }
        }
        $filtered = ReflectionSupport::filterEntriesByName($entries, $filter);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(ReflectionSupport::attributesArrayFromEntries($frame, $filtered));
        }
    }
}
