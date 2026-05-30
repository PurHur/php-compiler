<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionEnumUnitCase::getValue() — VM backed enum case value (#3800). */
final class ReflectionEnumUnitCaseGetValue extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getValue');
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
        if (null === $entry->backedType) {
            throw new \LogicException('Cannot get value of a pure enum case');
        }
        $caseLc = strtolower($caseName);
        foreach ($entry->enumCases as $case) {
            if (strtolower($case['name']) === $caseLc) {
                if (null !== $frame->returnVar) {
                    $frame->returnVar->copyFrom($case['value']);
                }

                return;
            }
        }
        throw new \LogicException('Enum '.$entry->name.' has no case named '.$caseName);
    }
}
