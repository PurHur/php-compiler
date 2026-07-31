<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionEnumBackedCase::getBackingValue() — VM (#5675). */
final class ReflectionEnumBackedCaseGetBackingValue extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getBackingValue');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionEnumBackedCase($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $enumName = ReflectionSupport::enumClassNameFromReflection($receiver);
        $caseName = ReflectionSupport::enumCaseNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $enumName);
        if (null === $entry || !$entry->isEnum || null === $entry->backedType) {
            throw new \LogicException('ReflectionEnumBackedCase refers to unknown backed enum in this compiler build');
        }
        $caseLc = \PHPCompiler\ClassConstName::key($caseName);
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
