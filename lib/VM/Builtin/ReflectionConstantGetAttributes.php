<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionConstant::getAttributes() — VM read path (#4136). */
final class ReflectionConstantGetAttributes extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getAttributes');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionConstant($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $constant = ReflectionSupport::constantNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionConstant refers to unknown class in this compiler build');
        }
        $constLc = strtolower($constant);
        $all = $entry->constAttributeNames[$constLc] ?? [];
        $allEntries = $entry->constAttributeEntries[$constLc] ?? [];
        $filter = null;
        if (isset($frame->calledArgs[1])) {
            $filter = VmReflection::stringArg($frame->calledArgs[1], 'ReflectionConstant::getAttributes() name');
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
