<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::getAttributes() — VM read path (#1936). */
final class ReflectionMethodGetAttributes extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getAttributes');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $method = ReflectionSupport::methodNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionMethod refers to unknown class in this compiler build');
        }
        $methodLc = strtolower($method);
        $all = $entry->methodAttributeNames[$methodLc] ?? [];
        $allEntries = $entry->methodAttributeEntries[$methodLc] ?? [];
        $filter = null;
        if (isset($frame->calledArgs[1])) {
            $filter = VmReflection::stringArg($frame->calledArgs[1], 'ReflectionMethod::getAttributes() name');
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
