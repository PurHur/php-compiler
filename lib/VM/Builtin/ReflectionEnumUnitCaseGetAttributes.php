<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionEnumUnitCase::getAttributes() — VM (#3800). */
final class ReflectionEnumUnitCaseGetAttributes extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getAttributes');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionEnumUnitCase($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $enumName = ReflectionSupport::classNameFromReflection($receiver);
        $caseName = ReflectionSupport::enumCaseNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $enumName);
        if (null === $entry || !$entry->isEnum) {
            throw new \LogicException('ReflectionEnumUnitCase refers to unknown enum in this compiler build');
        }
        $caseLc = strtolower($caseName);
        $entries = $entry->enumCaseAttributeEntries[$caseLc] ?? [];
        $filter = null;
        if (isset($frame->calledArgs[1])) {
            $filter = VmReflection::stringArg($frame->calledArgs[1], 'ReflectionEnumUnitCase::getAttributes() name');
        }
        $filtered = ReflectionSupport::filterEntriesByName($entries, $filter);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(ReflectionSupport::attributesArrayFromEntries($frame, $filtered));
        }
    }
}
